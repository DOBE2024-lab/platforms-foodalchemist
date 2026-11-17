<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\Reife\ConceptReifeAdapter;
use Platform\FoodAlchemist\Services\SlotEinheitService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Entscheid Dominique 17.11.: an Gericht-Positionen in Konzept und Paket nur Einheiten, die rechnen
 * (Spiegel von ConcepterAggregateService::portionsAequivalent/stueckModus). Andere werden beim Speichern abgelehnt
 * statt still als Portion gerechnet; Altbestand meldet die Reife (`einheit_unwirksam`), ohne Daten zu ändern.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(ConceptService::class);

    $e = fn (string $slug, string $dim, ?float $g = null, string $label = '') => FoodAlchemistVocabEinheit::create(array_filter([
        'team_id' => $this->rootTeam->id, 'slug' => $slug, 'display_de' => $label ?: $slug, 'dimension' => $dim, 'default_in_g' => $g,
    ], fn ($v) => $v !== null));
    $this->g = $e('g', 'mass', 1, 'Gramm');
    $this->kg = $e('kg', 'mass', 1000, 'Kilogramm');
    $this->ml = $e('ml', 'volume', null, 'Milliliter');
    $this->portion = $e('portion', 'count', null, 'Portion');
    $this->stk = $e('stk', 'count', null, 'Stück');
    $this->karton = $e('karton', 'count', null, 'Karton');
    $this->glas = $e('glas', 'count', null, 'Glas');

    // Gericht MIT Grammatur (Darreichung 250 g), ohne Stück-Ertrag
    $this->mitG = $this->makeRecipe($this->rootTeam, 'Gericht mit Grammatur', ['is_sales_recipe' => true, 'ek_total_eur' => 4.0, 'sales_unit_count' => 1]);
    $this->makeStandardDarreichung($this->mitG, 10.0, ['quantity_per_unit_g' => 250, 'ek_portion' => 4.0]);
    // Gericht OHNE Grammatur, MIT Stück-Ertrag; Darreichungs-Einheit Glas
    $this->ohneG = $this->makeRecipe($this->rootTeam, 'Gericht im Glas', ['is_sales_recipe' => true, 'ek_total_eur' => 10.0, 'yield_pieces' => 20, 'sales_unit_count' => 1, 'sales_quantity_per_unit_g' => null]);
    $this->makeStandardDarreichung($this->ohneG, 5.0, ['quantity_per_unit_g' => null, 'unit_vocab_id' => $this->glas->id]);

    $this->konzept = $this->svc->create($this->rootTeam, ['name' => 'Einheiten-Test']);
    $this->slot = function ($dish, ?int $unit = null) {
        $s = $this->svc->addSlot($this->rootTeam, $this->konzept->id, ['role' => 'Hauptgang']);

        return $this->svc->fillSlot($this->rootTeam, $s->id, ['sales_recipe_id' => $dish->id, 'quantity' => 1, 'unit_vocab_id' => $unit]);
    };
    $this->auswahl = function ($dish): array {
        $s = FoodAlchemistConceptSlot::where('sales_recipe_id', $dish->id)->firstOrFail();
        $s->setRelation('concept', $this->konzept->refresh());

        return app(SlotEinheitService::class)->wirksame($this->rootTeam, $dish, app(SlotEinheitService::class)->darreichungFuerSlot($s))
            ->pluck('slug')->sort()->values()->all();
    };
});

it('Auswahl: mit Grammatur g/kg + Portion, ohne Stück-Ertrag kein stk; ohne Grammatur kein g/kg, mit Stück-Ertrag stk + Einheit der Darreichung', function () {
    ($this->slot)($this->mitG);
    ($this->slot)($this->ohneG);

    expect(($this->auswahl)($this->mitG))->toBe(['g', 'kg', 'portion'])
        ->and(($this->auswahl)($this->ohneG))->toBe(['glas', 'portion', 'stk']);
});

it('Basisrezept: Menge ist Gramm — wirksam nur g, stk nur mit Stück-Ertrag', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Fond: Kalb', ['is_sales_recipe' => false, 'yield_kg' => 5]);
    $s = $this->svc->addSlot($this->rootTeam, $this->konzept->id, []);
    $this->svc->fillSlot($this->rootTeam, $s->id, ['sales_recipe_id' => $basis->id, 'type' => 'basisrezept', 'quantity' => 80]);

    expect(($this->auswahl)($basis))->toBe(['g']);
    expect(fn () => $this->svc->setSlotMengeEinheit($this->rootTeam, $s->id, 80, $this->kg->id))
        ->toThrow(RuntimeException::class, 'Gramm je Person');
});

it('Speichern lehnt nicht wirksame Einheiten mit Grund ab — Service, Concepter-Editor und MCP', function () {
    $s = ($this->slot)($this->ohneG);

    expect(fn () => $this->svc->setSlotMengeEinheit($this->rootTeam, $s->id, 100, $this->g->id))->toThrow(RuntimeException::class, 'keine Grammatur')
        ->and(fn () => $this->svc->setSlotMengeEinheit($this->rootTeam, $s->id, 1, $this->ml->id))->toThrow(RuntimeException::class, 'Volumen')
        ->and(fn () => $this->svc->setSlotMengeEinheit($this->rootTeam, $s->id, 1, $this->karton->id))->toThrow(RuntimeException::class, 'still als Portion');
    $m = ($this->slot)($this->mitG);
    expect(fn () => $this->svc->setSlotMengeEinheit($this->rootTeam, $m->id, 2, $this->stk->id))->toThrow(RuntimeException::class, 'Stück-Ertrag')
        ->and(fn () => $this->svc->fillSlot($this->rootTeam, $m->id, ['sales_recipe_id' => $this->ohneG->id, 'quantity' => 100, 'unit_vocab_id' => $this->g->id]))
        ->toThrow(RuntimeException::class, 'keine Grammatur');
    expect($s->fresh()->unit_vocab_id)->toBeNull()->and((int) $m->fresh()->sales_recipe_id)->toBe((int) $this->mitG->id);

    // Editor: Meldung am Feld, gespeichert wird nichts
    Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->konzept->id)
        ->set("slotForm.{$s->id}.unit_vocab_id", (string) $this->g->id)
        ->call('mengeSpeichern', $s->id)
        ->assertHasErrors(["slotForm.{$s->id}.unit_vocab_id"]);
    expect($s->fresh()->unit_vocab_id)->toBeNull();

    // MCP: concept_slots.PUT / POST und pakete Gerichte-Set → VALIDATION_ERROR, nichts angelegt
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam, []);
    $put = $reg->get('foodalchemist.concept_slots.PUT')->execute(['slot_id' => $s->id, 'felder' => ['quantity' => 1, 'unit_vocab_id' => $this->ml->id]], $ctx);
    expect($put->success)->toBeFalse()->and($put->errorCode)->toBe('VALIDATION_ERROR')->and($put->error)->toContain('Volumen');
    $vorher = FoodAlchemistConceptSlot::where('concept_id', $this->konzept->id)->count();
    $post = $reg->get('foodalchemist.concept_slots.POST')->execute(['concept_id' => $this->konzept->id, 'sales_recipe_id' => $this->ohneG->id, 'quantity' => 1, 'unit' => 'kg'], $ctx);
    expect($post->success)->toBeFalse()->and($post->errorCode)->toBe('VALIDATION_ERROR')
        ->and(FoodAlchemistConceptSlot::where('concept_id', $this->konzept->id)->count())->toBe($vorher);

    $paket = $this->svc->create($this->rootTeam, ['name' => 'Paket', 'kind' => 'paket']);
    expect(fn () => app(ConceptPaketService::class)->syncGerichte($this->rootTeam, $paket->id, [
        ['sales_recipe_id' => $this->mitG->id, 'quantity' => 1],
        ['sales_recipe_id' => $this->ohneG->id, 'quantity' => 100, 'unit_vocab_id' => $this->g->id],
    ]))->toThrow(RuntimeException::class);
    expect(FoodAlchemistConceptSlot::where('concept_id', $paket->id)->whereNotNull('sales_recipe_id')->count())->toBe(0);   // Vollersatz zurückgerollt
});

it('Concepter zeigt je Position nur wirksame Einheiten, Standard-Beschriftung aus der Darreichung, Altbestand gesperrt markiert', function () {
    $m = ($this->slot)($this->mitG);
    $o = ($this->slot)($this->ohneG);
    FoodAlchemistConceptSlot::whereKey($o->id)->update(['unit_vocab_id' => $this->karton->id]);   // Altbestand

    $html = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $this->konzept->id)->html();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $optionen = function (int $slotId) use ($dom): array {
        foreach ($dom->getElementsByTagName('select') as $sel) {
            if ($sel->getAttribute('wire:model') === "slotForm.{$slotId}.unit_vocab_id") {
                $aus = [];
                foreach ($sel->getElementsByTagName('option') as $o) {
                    $aus[] = trim($o->textContent) . ($o->hasAttribute('disabled') ? '|gesperrt' : '');
                }

                return $aus;
            }
        }

        return [];
    };

    expect($optionen($m->id))->toBe(['Portion (Standard)', 'g', 'kg', 'portion'])
        ->and($optionen($o->id))->toBe(['Glas (Standard)', 'glas', 'portion', 'stk', 'karton (rechnet nicht)|gesperrt']);
});

it('Reife meldet Altbestand mit nicht wirksamer Einheit, ändert nichts', function () {
    $o = ($this->slot)($this->ohneG);
    $m = ($this->slot)($this->mitG, $this->kg->id);
    FoodAlchemistConceptSlot::whereKey($o->id)->update(['unit_vocab_id' => $this->ml->id]);

    $r = app(ConceptReifeAdapter::class)->messe($this->rootTeam, $this->konzept->id);
    $befund = collect($r['luecken'])->firstWhere('code', 'einheit_unwirksam');

    expect($befund)->not->toBeNull()->and($befund['schwere'])->toBe('wichtig')->and($befund['slot_ids'])->toBe([(int) $o->id])
        ->and((int) $o->fresh()->unit_vocab_id)->toBe((int) $this->ml->id);

    // Menge an der Alt-Position bleibt speicherbar (Einheit unverändert)
    $this->svc->setSlotMengeEinheit($this->rootTeam, $o->id, 3, $this->ml->id);
    expect((float) $o->fresh()->quantity)->toBe(3.0);
});

it('Rechnung unverändert: g/kg über die Grammatur, stk im Stück-Modus, leer = Portion', function () {
    $dar = $this->mitG->presentations()->first();
    expect(ConcepterAggregateService::portionsAequivalent(500, $this->g, $this->mitG, 250.0))->toBe(2.0)
        ->and(ConcepterAggregateService::portionsAequivalent(0.5, $this->kg, $this->mitG, 250.0))->toBe(2.0)
        ->and(ConcepterAggregateService::portionsAequivalent(3, null, $this->mitG, 250.0))->toBe(3.0)
        ->and(ConcepterAggregateService::stueckModus($this->stk, $this->ohneG))->toBeTrue();

    $m = ($this->slot)($this->mitG, $this->g->id);
    $this->svc->setSlotMengeEinheit($this->rootTeam, $m->id, 500, $this->g->id);
    $zeile = collect($this->svc->preisCockpit($this->konzept->refresh())['zeilen'])->firstWhere('slot_id', $m->id);
    expect((float) $zeile['ek'])->toBe(8.0)->and((float) $zeile['price'])->toBe(20.0)   // 500 g ÷ 250 g = 2 Portionen
        ->and($dar->quantity_per_unit_g)->toBe(250.0);

    $o = ($this->slot)($this->ohneG, $this->stk->id);
    $this->svc->setSlotMengeEinheit($this->rootTeam, $o->id, 2, $this->stk->id);
    $zeile = collect($this->svc->preisCockpit($this->konzept->refresh())['zeilen'])->firstWhere('slot_id', $o->id);
    expect((float) $zeile['ek'])->toBe(1.0);                                                // 10 € ÷ 20 Stück × 2
});
