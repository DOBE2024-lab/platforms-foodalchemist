<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
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
    $this->modalName = [RecipeModal::class => 'recipe-modal', VkModal::class => 'vk-modal'];
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

it('Abbrechen: der eingebettete Zutaten-/Komponenten-Editor wird neu gemountet, verworfene Zeilen verschwinden', function (string $modal) {
    // Die Zeilen leben im Client des IngredientEditor; neu vom Server kommen sie nur über einen neuen wire:key
    // (…-v{zutatenVersion} in recipe-modal/vk-modal.blade.php). Vorher blieb der Zähler im VkModal beim Abbrechen gleich.
    $lw = Livewire::test($modal)->call('oeffnen', $this->rezepte[$modal]->id)->call('bearbeitenStarten');
    $vorher = $lw->get('zutatenVersion');

    $lw->call('bearbeitenAbbrechen');

    expect($lw->get('zutatenVersion'))->not->toBe($vorher);
})->with('rezept_modale');

it('Geschlossenes Modal: zutaten-persistiert gibt die Sperre eines anderen offenen Editors desselben Nutzers nicht frei', function (string $modal) {
    $rezept = $this->rezepte[$modal];
    $sperre = app(BearbeitungssperreService::class);
    $anna = auth()->user();

    $lw = Livewire::test($modal)->call('oeffnen', $rezept->id)->call('bearbeitenStarten');
    $lw->dispatch('modal.closed', name: $this->modalName[$modal]);           // per X geschlossen: eigene Sperre frei
    expect($sperre->haelt('recipe', $rezept->id, $anna->id))->toBeFalse()
        ->and($lw->get('istOffen'))->toBeFalse();

    // Ein zweiter Editor desselben Nutzers holt sich die Sperre auf dasselbe Rezept (gleicher Schlüssel recipe/id) …
    expect($sperre->sperren('recipe', $rezept->id, $anna->id, 'Anna')['ok'])->toBeTrue();
    // … und dessen Zutaten-Save darf über das geschlossene Modal nicht seine Sperre lösen.
    $lw->dispatch('zutaten-persistiert', recipeId: $rezept->id);

    expect($sperre->haelt('recipe', $rezept->id, $anna->id))->toBeTrue();
})->with('rezept_modale');

it('Offenes Modal: zutaten-persistiert beendet die Bearbeitung wie bisher', function (string $modal) {
    $rezept = $this->rezepte[$modal];
    $lw = Livewire::test($modal)->call('oeffnen', $rezept->id)->call('bearbeitenStarten');
    expect(app(BearbeitungssperreService::class)->haelt('recipe', $rezept->id, auth()->id()))->toBeTrue();

    $lw->dispatch('zutaten-persistiert', recipeId: $rezept->id);

    expect(app(BearbeitungssperreService::class)->haelt('recipe', $rezept->id, auth()->id()))->toBeFalse();
})->with('rezept_modale');
