<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Enums\ProductionOrderStatus;
use Platform\FoodAlchemist\Livewire\Produktion\Tagesplan;
use Platform\FoodAlchemist\Livewire\Produktion\TagesplanAnleitung;
use Platform\FoodAlchemist\Livewire\Produktion\TagesplanFeedback;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionOrderLine as Line;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\ProductionOrderService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (S6): Rauchtest vor dem Zerlegen von Produktion/Tagesplan „nach Modus“. Jeder Modus rendert und jede öffentliche
 * Aktion läuft einmal ohne Exception: Dashboard, Editor, Wall (Lanes/Mise, Filter), Anleitung, Gericht-Overlay, Feedback.
 * Fachlich prüfen ProduktionTagesplanTest, ProduktionWandmonitorPassTest und ProduktionAbarbeitenTest.
 * Nach dem Schnitt ziehen die Aufrufe mit in die Kind-Komponenten (Liste alt → neu im PR).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));
    $svc = app(ProductionOrderService::class);
    $mk = fn (string $key, string $name, bool $vk) => FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
        'status' => 'approved', 'is_sales_recipe' => $vk, 'yield_kg' => 2.0, 'work_time_min' => 60,
        'preparation' => "1. Knochen rösten\n2. Ansetzen\n3. Passieren"]);
    $fond = $mk('fond', 'Brauner Fond', false);
    $braten = $mk('braten', '[HG] Rinderbraten', true);
    $this->order = $svc->saveNew($this->rootTeam, '2026-08-20', 'Rauch-Tagesplan', [
        ['source_ref' => 'r:fond', 'recipe_id' => $fond->id, 'amount_kg' => 6.0],
        ['source_ref' => 'r:braten', 'recipe_id' => $braten->id, 'amount_kg' => 4.0],
    ]);
    $svc->setStatus($this->rootTeam, $this->order->id, ProductionOrderStatus::InProgress);
    $this->zeile = Line::where('production_order_id', $this->order->id)->where('recipe_id', $fond->id)->first();
});

it('Dashboard: rendert, Fenster, Tag verschieben, heute, Zeitraum', function () {
    Livewire::test(Tagesplan::class, ['von' => '2026-08-20'])
        ->assertSeeHtml('data-tagesplanung-dashboard')
        ->call('waehleDashboardFenster', 7)->call('dashboardTagVerschieben', 1)->call('dashboardHeute')
        ->call('verschiebe', -1)->call('heute')->assertOk();
});

it('Editor: rendert mit Auftrag, Posten und Tag wählen, Vorlauf, Abhaken, Vorschlag', function () {
    Livewire::test(Tagesplan::class, ['von' => '2026-08-20'])->set('modus', 'editor')
        ->assertSee('Brauner Fond')
        ->call('postenWaehlen', null)->call('waehleTag', '2026-08-20')
        ->call('vorlaufSetzen', $this->zeile->id, '1')
        ->call('abhaken', $this->zeile->id)
        ->call('vorschlagen')->call('vorschlagVerwerfen')
        ->assertOk();
});

it('Wall: Lanes und Mise, Gruppenfilter, Mise abhaken, Anleitung und Gericht-Overlay', function () {
    $wand = Livewire::test(Tagesplan::class, ['von' => '2026-08-20', 'display' => 'wall'])->set('modus', 'editor')->assertOk();
    foreach (['lanes', 'mise'] as $ansicht) {
        $wand->call('wallAnsichtSetzen', $ansicht)->assertOk();
    }
    foreach (['alle', 'gerichte', 'basis'] as $filter) {
        $wand->call('wallGruppenFilterSetzen', $filter)->assertOk();
    }
    $wand->call('abhakenMise', $this->zeile->id)
        ->call('oeffneAnleitung', $this->zeile->id)->assertDispatched('tagesplan-anleitung-oeffnen')
        ->call('zeileGeaendert')
        ->call('oeffneGericht', 'r:braten')->call('gerichtSchliessen')
        ->assertOk();
    // Anleitung ist seit 98 · 3.3 S6 ein Kind (TagesplanAnleitung).
    Livewire::test(TagesplanAnleitung::class, ['von' => '2026-08-20', 'bis' => '2026-09-02'])
        ->call('oeffneAnleitung', $this->zeile->id)->call('anleitungStarten')
        ->call('anleitungStepUmschalten', 0)->call('anleitungAlleStepsUmschalten')->call('anleitungSchliessen')
        ->assertOk();
});

it('Feedback: Grund, Werte, Speichern zur Zeile', function () {
    $grund = array_key_first((array) config('foodalchemist.feedback_gruende', [])) ?? 'zu_salzig';
    Livewire::test(TagesplanAnleitung::class, ['von' => '2026-08-20', 'bis' => '2026-09-02'])
        ->call('oeffneAnleitung', $this->zeile->id)
        ->assertSeeHtml('wire:name="foodalchemist.produktion.tagesplan-feedback"');   // Kind gemountet (nach Aktionen nur als Hülle im HTML)
    Livewire::test(TagesplanFeedback::class, ['lineId' => $this->zeile->id])
        ->call('feedbackGrundUmschalten', $grund)
        ->call('feedbackSetzen', 'geschmack', 4)
        ->call('feedbackSpeichern')
        ->assertOk();
});
