<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SignalStatus;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Services\SignalDetektorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Regelwerk LA §10 (Dominique 01.11.): Summen-Signal „Grundprodukte mit Allergen-Widerspruch“ je Team + Konfidenz/Review sofort nachziehen.
 * Widerspruch = NUR enthalten ↔ nicht_enthalten (Spuren zählen nicht), dieselbe Regel wie GpAggregateService::allergenKonfidenz. Alle Signal-Läufe über
 * SignalDetektorService::laufen() (Weg des Schedulers).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->sup2 = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs']);
    $this->gp = fn (string $name, ?\Platform\Core\Models\Team $team = null) => tap($this->makeGp($team ?? $this->rootTeam, $name), fn ($g) => $g->update(['status' => 'approved']));
    // Artikel mit Milch-Wert, direkt an das GP gehängt (Bestand, an der Verknüpfungs-Prüfung vorbei)
    $this->la = function ($gp, string $nr, string $milch, $lieferant = null) {
        $la = FoodAlchemistSupplierItem::create(['team_id' => $gp->team_id, 'supplier_id' => ($lieferant ?? $this->sup)->id, 'article_number' => $nr, 'designation' => "Artikel {$nr}"]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $gp->team_id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        DB::table('foodalchemist_item_allergens')->insert(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $gp->team_id, 'supplier_item_id' => $la->id,
            'allergen_milk' => $milch, 'created_at' => now(), 'updated_at' => now()]);

        return $la;
    };
    $this->signale = fn (?int $teamId = null) => FoodAlchemistSignal::where('team_id', $teamId ?? $this->rootTeam->id)->where('type', SignalTyp::GpAllergenWiderspruch->value)->get();
    $this->lauf = fn ($team = null) => app(SignalDetektorService::class)->laufen($team ?? $this->rootTeam);
});

it('laufen() erzeugt EIN Summen-Signal mit Zahl und Beispielen (GP, Allergen, Artikel, Lieferant), Allergen-Wert unverändert', function () {
    $a = ($this->gp)('Sahne');
    ($this->la)($a, 'A-1', 'enthalten');
    ($this->la)($a, 'A-2', 'nicht_enthalten', $this->sup2);
    $b = ($this->gp)('Butter');
    ($this->la)($b, 'B-1', 'enthalten');
    ($this->la)($b, 'B-2', 'nicht_enthalten');
    $ok = ($this->gp)('Mehl');
    ($this->la)($ok, 'C-1', 'nicht_enthalten');
    ($this->la)($ok, 'C-2', 'nicht_enthalten');

    ($this->lauf)();

    $s = ($this->signale)();
    expect($s)->toHaveCount(1)->and($s[0]->payload['anzahl'])->toBe(2)->and($s[0]->title)->toContain('2 Grundprodukte')
        ->and($s[0]->dedup_key)->toBe('gp-allergen-widerspruch');
    $bsp = collect($s[0]->payload['beispiele'])->firstWhere('name', 'Sahne');
    expect($bsp['allergene'][0]['allergen'])->toBe('Milch')
        ->and($bsp['allergene'][0]['enthalten'][0])->toMatchArray(['artikel' => 'A-1 Artikel A-1', 'lieferant' => 'Hanos'])
        ->and($bsp['allergene'][0]['nicht_enthalten'][0])->toMatchArray(['artikel' => 'A-2 Artikel A-2', 'lieferant' => 'Chefs'])
        ->and(collect($s[0]->payload['beispiele'])->pluck('name')->all())->not->toContain('Mehl');
    // ALL-MAXIMAL bleibt: der Wert des GP ist „enthalten“, das Signal ändert ihn nie
    expect(app(GpAggregateService::class)->allergene($a->fresh())['milk']['value']->value)->toBe('enthalten');
});

it('Spuren zählen nicht: enthalten↔spuren und enthalten+spuren+nicht_enthalten sind KEIN Widerspruch (Regelwerk §10 Wortlaut)', function () {
    $a = ($this->gp)('Sahne');
    ($this->la)($a, 'A-1', 'enthalten');
    ($this->la)($a, 'A-2', 'spuren');
    $b = ($this->gp)('Butter');
    ($this->la)($b, 'B-1', 'enthalten');
    ($this->la)($b, 'B-2', 'spuren');
    ($this->la)($b, 'B-3', 'nicht_enthalten');

    ($this->lauf)();

    expect(($this->signale)())->toHaveCount(0);
});

it('selbstschließend: nach dem Lösen des falschen Artikels schließt der nächste Lauf das Signal; ein neuer Widerspruch öffnet es wieder', function () {
    $gp = ($this->gp)('Sahne');
    ($this->la)($gp, 'A-1', 'enthalten');
    $falsch = ($this->la)($gp, 'A-2', 'nicht_enthalten');
    ($this->lauf)();
    ($this->lauf)();
    expect(($this->signale)())->toHaveCount(1)->and(($this->signale)()[0]->seen_count)->toBe(2);

    app(LeadLaService::class)->entknuepfen($this->rootTeam, $gp->fresh(), $falsch->id);
    ($this->lauf)();
    $s = ($this->signale)()[0];
    expect($s->status)->toBe(SignalStatus::Erledigt)->and($s->payload['auto_geschlossen'])->toContain('automatisch geschlossen');

    $neu = ($this->la)($gp, 'A-3', 'nicht_enthalten');
    ($this->lauf)();
    expect(($this->signale)()->where('status', SignalStatus::Offen)->count())->toBe(1);
});

it('nur GPs des Teams, nur approved Lebensmittel: fremdes Team und tentative GPs erzeugen hier nichts', function () {
    $fremd = ($this->gp)('Fremde Sahne', $this->childA);
    ($this->la)($fremd, 'F-1', 'enthalten');
    ($this->la)($fremd, 'F-2', 'nicht_enthalten');
    $tentativ = $this->makeGp($this->rootTeam, 'Entwurf');
    $tentativ->update(['status' => 'tentative']);
    ($this->la)($tentativ, 'T-1', 'enthalten');
    ($this->la)($tentativ, 'T-2', 'nicht_enthalten');

    ($this->lauf)();
    expect(($this->signale)())->toHaveCount(0);

    ($this->lauf)($this->childA);
    expect(($this->signale)($this->childA->id))->toHaveCount(1);
});

it('Konfidenz wird SOFORT nachgezogen: Verknüpfen, LA-Allergen ändern, Lösen — ohne Backfill-Befehl; manual/ki-GPs bleiben unangetastet', function () {
    $gp = ($this->gp)('Sahne');
    $erster = ($this->la)($gp, 'A-1', 'nicht_enthalten');
    $zweiter = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'article_number' => 'A-2', 'designation' => 'Artikel A-2']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $zweiter->id, 'gp_id' => null]);
    FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $zweiter->id, 'allergen_milk' => 'nicht_enthalten']);
    expect($gp->fresh()->allergens_confidence)->toBeNull();

    app(LeadLaService::class)->verknuepfen($this->rootTeam, $gp->fresh(), $zweiter->id);
    expect((float) $gp->fresh()->allergens_confidence)->toBe(1.0);                      // Verknüpfen: gleich ⇒ HIGH

    $zweiter->allergens()->first()->update(['allergen_milk' => 'enthalten']);           // LA-Allergen ändert sich (Eloquent: Artikel-Modal, Produktpass)
    expect((float) $gp->fresh()->allergens_confidence)->toBe(0.33);                     // Widerspruch ⇒ LOW, sofort

    app(LeadLaService::class)->entknuepfen($this->rootTeam, $gp->fresh(), $zweiter->id);
    expect((float) $gp->fresh()->allergens_confidence)->toBe(1.0);                      // Lösen ⇒ wieder HIGH

    $manuell = ($this->gp)('Handgepflegt');
    $manuell->update(['allergens_source' => 'manual', 'allergens_confidence' => 0.5]);
    ($this->la)($manuell, 'M-1', 'enthalten');
    FoodAlchemistItemAllergen::where('supplier_item_id', FoodAlchemistSupplierItemStructure::where('gp_id', $manuell->id)->value('supplier_item_id'))->first()->update(['allergen_milk' => 'nicht_enthalten']);
    expect((float) $manuell->fresh()->allergens_confidence)->toBe(0.5);                 // Provenienz-Schutz des Backfills gilt auch hier
});

it('Signal-Panel: die Beispiele lösen als Grundprodukte auf (Link + Weitere Befunde), mit Beleg je GP; das alte Summen-Signal wird geschlossen', function () {
    $alt = app(\Platform\FoodAlchemist\Services\SignalService::class)->erzeuge($this->rootTeam, SignalTyp::DatenqualitaetGpLa,
        \Platform\FoodAlchemist\Enums\SignalSeverity::Kritisch, '147 GPs mit Allergen-Konflikt', ['dedup_key' => 'dq-gp-allergen-konflikt', 'source' => 'data-quality', 'payload' => ['anzahl' => 147]]);
    $a = ($this->gp)('Sahne');
    ($this->la)($a, 'A-1', 'enthalten');
    ($this->la)($a, 'A-2', 'nicht_enthalten', $this->sup2);

    ($this->lauf)();

    $sig = ($this->signale)()->first();
    $res = app(\Platform\FoodAlchemist\Services\SignalObjectService::class)->betroffene($this->rootTeam, $sig);
    expect($res['items'])->toHaveCount(1)
        ->and($res['items'][0])->toMatchArray(['kind' => 'gp', 'id' => $a->id, 'name' => 'Sahne'])
        ->and($res['items'][0]['hinweis'])->toBe('Milch: enthalten laut Hanos A-1 Artikel A-1, nicht enthalten laut Chefs A-2 Artikel A-2')
        ->and($res['live'])->toBeFalse()
        // Rückrichtung: am GP taucht das Signal in „Weitere Befunde“ auf
        ->and(collect(app(\Platform\FoodAlchemist\Services\SignalObjectService::class)->signaleAmObjekt($this->rootTeam, 'gp', $a->id))->pluck('id')->all())->toContain($sig->id)
        ->and($alt->fresh()->status)->toBe(SignalStatus::Erledigt);

    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Signale\DetailPanel::class)->call('zeige', $sig->id)
        ->assertSeeHtml('data-signal-objekt="gp-' . $a->id . '"')->assertSeeHtml('data-signal-objekt-hinweis')
        ->assertDontSee('erscheinen, sobald diese behoben sind');

    // Mehr Treffer als Beispiele (Payload-Auszug): das Panel verspricht kein Nachrücken, sondern nennt den Auszug
    $sig->update(['payload' => array_merge($sig->payload, ['anzahl' => 25])]);
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Signale\DetailPanel::class)->call('zeige', $sig->id)
        ->assertSee('Das Signal führt nur diese Beispiele')->assertDontSee('erscheinen, sobald diese behoben sind');
});
