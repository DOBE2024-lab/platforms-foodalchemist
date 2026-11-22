<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.2c: Rezept-Modal und VK-Modal teilen Bild, KI, Sensorik und Sperr-Haken (HatRezeptModalWerkzeuge). Wo die
 * Kopien vorher auseinanderliefen, gilt jetzt dasselbe Verhalten in beiden Modalen (Kurator-Entscheid, umkehrbar,
 * im Sammelbericht an Dominique). Jeder Fall läuft für beide Modale.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Anna'));
    $this->rezepte = [
        RecipeModal::class => FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'fond-gleichlauf', 'name' => 'Fond: Gleichlauf', 'status' => 'draft',
        ]),
        VkModal::class => FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => 'gericht-gleichlauf', 'name' => '[HG] Gleichlauf', 'status' => 'draft',
            'is_sales_recipe' => true,
        ]),
    ];
});

dataset('rezept_modale', [RecipeModal::class, VkModal::class]);

it('Gerichtsbild: ohne eigene Bearbeitungssperre speichert der Upload nichts, mit Sperre schon', function (string $modal) {
    $rezept = $this->rezepte[$modal];

    // Vorher fehlte die Prüfung im VkModal: updated-Hooks laufen nicht über den call-Haken der Sperre.
    Livewire::test($modal)->call('oeffnen', $rezept->id)
        ->set('dishImageUpload', UploadedFile::fake()->image('gericht.jpg', 400, 300));
    expect($rezept->fresh()->image_path)->toBeNull();

    Livewire::test($modal)->call('oeffnen', $rezept->id)->call('bearbeitenStarten')
        ->set('dishImageUpload', UploadedFile::fake()->image('gericht.jpg', 400, 300))
        ->assertSet('fehler', null);
    expect($rezept->fresh()->image_path)->not->toBeNull();
})->with('rezept_modale');

it('Copilot: Prüfen, aus der Ablage holen und Verwerfen sind ohne Sperre frei, Übernehmen nicht', function (string $modal) {
    // Vorher brauchte im VkModal schon die lesende Copilot-Prüfung „Bearbeiten“. copilotAusAblage liest nur:
    // RecipeFindingService::offeneBefundeFuer + RecipeReviewService::bewerte schreiben nichts.
    $lw = Livewire::test($modal)->call('oeffnen', $this->rezepte[$modal]->id);
    foreach (['copilotPruefen', 'copilotAusAblage', 'copilotVerwerfen'] as $methode) {
        expect($lw->instance()->sperreErlaubt($methode))->toBeTrue("{$modal}::{$methode}");
    }
    foreach (['copilotUebernehmen', 'copilotAlleUebernehmen', 'copilotBefundVerwerfen'] as $methode) {
        expect($lw->instance()->sperreErlaubt($methode))->toBeFalse("{$modal}::{$methode}");
    }
})->with('rezept_modale');
