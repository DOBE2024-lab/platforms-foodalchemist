<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.1e · Mess-Test 6 (Bericht 97): Concepter-Aufbau-Tab mit 12 Slots. Vorher lud der Tab je Slot die tauschbaren Pakete,
 * die Formen des Gerichts, die passende Form zur Konzept-Servierform, das Zubehör und „Variante fehlt" einzeln (~5 Abfragen je Slot).
 * Abnahme: Die Abfragen wachsen nicht mit der Zahl der Slots; Anzeige (Form-Auswahl, Darreichung, „Variante fehlt") bleibt gleich.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->teller = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->buffet = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->baueKonzept = function (int $slots): FoodAlchemistConcept {
        $concept = $this->makeConcept($this->rootTeam, "Menü {$slots}", ['serving_form_id' => $this->teller->id]);
        for ($i = 0; $i < $slots; $i++) {
            $r = $this->makeRecipe($this->rootTeam, "Gericht {$slots}-{$i}", ['is_sales_recipe' => true, 'ek_total_eur' => 3.0]);
            // Jedes Gericht: Standard „Buffet" + (außer dem ersten) eine Teller-Form → Form-Auswahl; das erste meldet „Variante fehlt".
            FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->buffet->id,
                'is_standard' => true, 'ek_portion' => 3.0, 'sales_net' => 9.0]);
            if ($i > 0) {
                FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $r->id, 'serving_form_id' => $this->teller->id,
                    'is_standard' => false, 'ek_portion' => 3.5, 'sales_net' => 11.0]);
            }
            $this->makeConceptSlot($concept, ['recipe_id' => $r->id, 'position' => $i]);
        }

        return $concept;
    };
    // Zählt die Abfragen eines Neu-Renders im Aufbau-Tab; `formen` = nur die Formen-/Servierform-/Einheiten-Abfragen (Ziel von 2.1e).
    $this->zaehle = function (FoodAlchemistConcept $concept): array {
        $editor = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $concept->id);
        $n = ['gesamt' => 0, 'formen' => 0];
        DB::listen(function ($q) use (&$n) {
            $n['gesamt']++;
            if (preg_match('/from "foodalchemist_(recipe_presentations|serving_forms|vocab_units)"/', $q->sql)) {
                $n['formen']++;
            }
        });
        $editor->call('$refresh');

        return $n;
    };
});

it('Mess-Test 6: die Abfragen des Aufbau-Tabs wachsen nicht mit der Zahl der Slots', function () {
    $drei = ($this->zaehle)(($this->baueKonzept)(3));
    $zwoelf = ($this->zaehle)(($this->baueKonzept)(12));

    // 2.1e (22.11.2026): 3 Slots 46, 12 Slots 73 (vorher 56 / 119) — Rest waren 3 Sensorik-Abfragen je Gericht der Menü-Bewertung.
    // Folgepaket: SensorikService::fuerRezepte bündelt sie; jetzt gleich viele Abfragen für 3 und 12 Slots.
    expect($zwoelf['formen'])->toBe($drei['formen'])
        ->and($zwoelf['gesamt'])->toBe($drei['gesamt']);
});

it('Aufbau zeigt weiter Form-Auswahl, aufgelöste Darreichung und „Variante fehlt" je Slot', function () {
    $concept = ($this->baueKonzept)(3);
    $slots = $concept->slots()->orderBy('position')->get();
    $v = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $concept->id)->viewData('darreichungOptionen');

    expect($v)->not->toHaveKey($slots[0]->id)                        // nur eine Form
        ->and(array_column($v[$slots[1]->id], 'label'))->toBe(['Buffet', 'Teller']);   // Standard zuerst
    $editor = Livewire::test(Editor::class)->call('oeffnen', 'concepts', $concept->id);
    expect($editor->viewData('varianteFehlt'))->toBe([$slots[0]->id => true])
        ->and($editor->viewData('darreichungInfo'))->toBe([$slots[0]->id => 'Standard: Buffet', $slots[1]->id => 'Teller', $slots[2]->id => 'Teller']);
});

