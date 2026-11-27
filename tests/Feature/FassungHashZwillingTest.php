<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Rezept\DarreichungService;
use Platform\FoodAlchemist\Services\Fassung\ConceptFassungService;
use Platform\FoodAlchemist\Services\Fassung\FassungService;
use Platform\FoodAlchemist\Services\Fassung\FormatFassungService;
use Platform\FoodAlchemist\Services\Concepter\FormatService;
use Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\FassungHashAlt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.2 Zwillingstest: Die Fassungs-Dienste (Rezept, Konzept/Paket, Format) hashen nach dem Umbau auf die Basisklasse
 * bit-gleich wie vorher (eingefrorene Kopie in tests/Support/FassungHashAlt.php). Sonst „wiche“ jede bestehende Fassung ab
 * und das nächste Veröffentlichen erzeugte für alle Abonnenten neue Fassungen ohne Inhaltsänderung.
 * Geprüft am veröffentlichten Stand (gespeicherter inhalt_hash) und nach Änderungen an Kopf, Zutat, Slot und Wording.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->actingAs($this->kurator);

    $knochen = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'zw|knochen', 'name' => 'Kalbsknochen']);
    $butter = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'zw|butter', 'name' => 'Butter']);
    $this->fond = $this->makeRecipe($this->master, 'Kalbsfond', ['is_sales_recipe' => false]);
    $this->makeIngredient($this->fond, 'Kalbsknochen', $knochen, '1000');
    app(RecipeRecomputeService::class)->recomputePipeline($this->fond->id);
    $this->gericht = $this->makeRecipe($this->master, 'Kalbsjus am Teller', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Kalbsfond', null, '500')->update(['referenced_recipe_id' => $this->fond->id]);
    $this->butterZeile = $this->makeIngredient($this->gericht, 'Butter', $butter, '100', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);
    $teller = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $klein = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'klein', 'label' => 'Klein']);
    $dar = app(DarreichungService::class);
    $this->grammaturAltbestand($dar->anlegen($this->master, $this->gericht->id, $teller->id), 200);
    $kleine = $dar->setzeAnteil($this->master, $dar->anlegen($this->master, $this->gericht->id, $klein->id)->id, 50);
    $portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $cs = app(ConceptService::class);
    $this->paket = $cs->createPaket($this->master, ['name' => 'Jus-Duo']);
    $sp = $cs->addSlot($this->master, $this->paket->id, ['role' => 'Vorspeise']);
    $cs->fillSlot($this->master, $sp->id, ['recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $portion->id]);
    $cs->setSlotDarreichung($this->master, $sp->id, $kleine->id);
    $this->konzept = $cs->create($this->master, ['name' => 'Jus-Menü']);
    $s1 = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Vorspeise']);
    $cs->fillSlot($this->master, $s1->id, ['embedded_concept_id' => $this->paket->id]);
    $this->hauptSlot = $cs->addSlot($this->master, $this->konzept->id, ['role' => 'Hauptgang']);
    $cs->fillSlot($this->master, $this->hauptSlot->id, ['recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $portion->id]);
    $cs->setSlotDarreichung($this->master, $this->hauptSlot->id, $kleine->id);
    $cs->addBlock($this->master, $this->konzept->id, 'header', ['title' => 'Unser Menü']);

    $this->format = app(FormatService::class)->create($this->master, ['name' => 'Gala-Format']);
    FoodAlchemistFormatSlot::create(['team_id' => $this->master->id, 'format_id' => $this->format->id, 'type' => 'header', 'position' => 0]);
    $this->formatSlot = FoodAlchemistFormatSlot::create(['team_id' => $this->master->id, 'format_id' => $this->format->id, 'type' => 'concept',
        'concept_id' => $this->konzept->id, 'position' => 1, 'payload_json' => ['wording_overrides' => [(string) $this->hauptSlot->id => 'Hausgemachter Jus']]]);

    $this->rezeptSvc = app(FassungService::class);
    $this->konzeptSvc = app(ConceptFassungService::class);
    $this->formatSvc = app(FormatFassungService::class);
    $this->rezeptSvc->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator);
    $this->konzeptSvc->veroeffentlichen($this->master, $this->paket->id, 'Erst', $this->kurator);
    $this->konzeptSvc->veroeffentlichen($this->master, $this->konzept->id, 'Erst', $this->kurator);
    $this->formatSvc->veroeffentlichen($this->master, $this->format->id, 'Erst', $this->kurator);

    $privat = fn (object $o, string $m, ...$a) => (fn () => $this->{$m}(...$a))->call($o);
    $letzte = fn (string $klasse, int $id) => $klasse::mitFassungen()->ohneTeamSicht('test')->where('fassung_von_id', $id)->where('ist_fassung', true)
        ->orderByDesc('fassung_nr')->first();
    $this->letzte = $letzte;

    // Je Art: [neuer Hash, alter Hash] am aktuellen Arbeitsstand
    $this->paar = [
        'rezept' => function (FoodAlchemistRecipe $r, array $map) use ($privat) {
            $r = FoodAlchemistRecipe::ohneTeamSicht('test')->findOrFail($r->id);

            return [$privat($this->rezeptSvc, 'inhaltHash', $r, $map), FassungHashAlt::rezept($r, $map)];
        },
        'konzept' => function (FoodAlchemistConcept $c) use ($privat) {
            $c = FoodAlchemistConcept::findOrFail($c->id);
            $slots = $c->slots()->get();
            $ziele = $privat($this->konzeptSvc, 'zieleSammeln', $slots)[0];

            return [$privat($this->konzeptSvc, 'inhaltHash', $c, $slots, $ziele), FassungHashAlt::konzept($c, $slots, $ziele)];
        },
        'format' => function () use ($privat) {
            $f = FoodAlchemistFormat::findOrFail($this->format->id);
            $slots = $f->slots()->get();
            $ziele = $privat($this->formatSvc, 'zieleSammeln', $slots);

            return [$privat($this->formatSvc, 'inhaltHash', $f, $slots, $ziele), FassungHashAlt::format($f, $slots, $ziele)];
        },
    ];
});

it('veröffentlichter Stand: neue Dienste hashen wie die eingefrorene Kopie und wie der gespeicherte inhalt_hash', function () {
    $fondFassung = ($this->letzte)(FoodAlchemistRecipe::class, $this->fond->id);
    $faelle = [
        [($this->paar['rezept'])($this->fond, []), $fondFassung->inhalt_hash],
        [($this->paar['rezept'])($this->gericht, [$this->fond->id => (int) $fondFassung->id]), ($this->letzte)(FoodAlchemistRecipe::class, $this->gericht->id)->inhalt_hash],
        [($this->paar['konzept'])($this->paket), ($this->letzte)(FoodAlchemistConcept::class, $this->paket->id)->inhalt_hash],
        [($this->paar['konzept'])($this->konzept), ($this->letzte)(FoodAlchemistConcept::class, $this->konzept->id)->inhalt_hash],
        [($this->paar['format'])(), ($this->letzte)(FoodAlchemistFormat::class, $this->format->id)->inhalt_hash],
    ];
    foreach ($faelle as $i => [[$neu, $alt], $gespeichert]) {
        expect($neu)->toBe($alt, "Fall {$i}: neu ≠ alt")->and($neu)->toBe($gespeichert, "Fall {$i}: neu ≠ gespeichert");
    }
    // Und die Status-Oberfläche sieht keine Abweichung (gleiche Hash-Logik)
    expect($this->rezeptSvc->status($this->gericht->refresh())['weicht_ab'])->toBeFalse()
        ->and($this->konzeptSvc->status($this->konzept->refresh())['weicht_ab'])->toBeFalse()
        ->and($this->formatSvc->status($this->format->refresh())['weicht_ab'])->toBeFalse();
});

it('nach Änderungen an Kopf, Zutat, Slot und Wording bleiben neu und alt gleich (und weichen vom Gespeicherten ab)', function () {
    $this->gericht->forceFill(['name' => 'Kalbsjus kräftig'])->save();
    $this->butterZeile->update(['quantity' => '120']);
    $this->konzept->forceFill(['name' => 'Jus-Menü Herbst'])->save();
    $this->paket->forceFill(['claim' => 'Zwei Jus'])->save();
    $this->formatSlot->update(['payload_json' => ['wording_overrides' => [(string) $this->hauptSlot->id => 'Jus vom Kalb']]]);
    $this->format->forceFill(['name' => 'Gala-Format Herbst'])->save();

    $fondId = (int) ($this->letzte)(FoodAlchemistRecipe::class, $this->fond->id)->id;
    foreach ([
        'gericht' => ($this->paar['rezept'])($this->gericht, [$this->fond->id => $fondId]),
        'paket' => ($this->paar['konzept'])($this->paket),
        'konzept' => ($this->paar['konzept'])($this->konzept),
        'format' => ($this->paar['format'])(),
    ] as $fall => [$neu, $alt]) {
        expect($neu)->toBe($alt, "{$fall}: neu ≠ alt");
    }
    expect($this->rezeptSvc->status($this->gericht->refresh())['weicht_ab'])->toBeTrue()
        ->and($this->konzeptSvc->status($this->konzept->refresh())['weicht_ab'])->toBeTrue()
        ->and($this->formatSvc->status($this->format->refresh())['weicht_ab'])->toBeTrue();
});
