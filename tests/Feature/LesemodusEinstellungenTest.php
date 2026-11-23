<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Handbuch\Panel as HandbuchPanel;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetailPanel;
use Platform\FoodAlchemist\Livewire\Settings\Behaelter;
use Platform\FoodAlchemist\Livewire\Settings\DnaFelder;
use Platform\FoodAlchemist\Livewire\Settings\Einkauf;
use Platform\FoodAlchemist\Livewire\Settings\FoodDna;
use Platform\FoodAlchemist\Livewire\Settings\Posten;
use Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKochequipment;
use Platform\FoodAlchemist\Tests\Support\LesemodusDom;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 · Lesemodus (Spec 65) in den Einstellungen: Anzeige bleibt ohne „Bearbeiten“ bedienbar, Design-Daten nicht.
 * Geprüft im gerenderten DOM (Vorfahr fieldset[disabled]) und am Server-Guard.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Anna'));
});

it('Präsentations-Designs: „EK-Ansicht im Link“ im Lesemodus gesperrt (DOM) und serverseitig zurückgesetzt; Vorschau-Steuerung frei', function () {
    $c = Livewire::test(PraesentationsDesigns::class)->assertSeeHtml('data-fa-lesemodus="1"');
    $html = $c->html();

    expect(LesemodusDom::gesperrt($html, '//input[@data-fa-vorschau-ek]'))->toBe([true])
        ->and(LesemodusDom::gesperrt($html, '//select[@id="design-vorschau-quelle"]'))->toBe([false]);

    // wire:model umgeht den call-Haken — der Hook setzt ohne Sperre zurück, auch „ungespeichert“
    $c->set('tokens.ek_im_link', true)
        ->assertSet('tokens.ek_im_link', false)
        ->assertSet('ungespeichert', false);

    $c->call('bearbeitenStarten')->assertSeeHtml('data-fa-lesemodus="0"')
        ->set('tokens.ek_im_link', true)
        ->assertSet('tokens.ek_im_link', true)
        ->assertSet('ungespeichert', true);
    expect(LesemodusDom::gesperrt($c->html(), '//input[@data-fa-vorschau-ek]'))->toBe([false]);
});

it('Behälter: „+ N weitere“ im Lesemodus bedienbar, „Neu“ bleibt gesperrt', function () {
    foreach (range(1, 13) as $i) {
        FoodAlchemistVocabKochequipment::create(['team_id' => $this->rootTeam->id, 'slug' => 'geraet-'.$i, 'name' => 'Gerät '.$i]);
    }
    $html = Livewire::test(Behaelter::class)->assertSeeHtml('data-fa-lesemodus="1"')->html();

    expect(LesemodusDom::bedienbar($html, '//*[@data-vokabular-alle="equipment"]'))->toBe([true])
        ->and(LesemodusDom::bedienbar($html, '//*[@data-behaelter-neu-oeffnen]'))->toBe([false]);
});

it('Posten: Zeile aufklappen im Lesemodus bedienbar, Felder darin bleiben gesperrt', function () {
    $c = Livewire::test(Posten::class)->call('bearbeitenStarten')
        ->set('neu.name', 'Warme Küche')->set('neu.group_name', 'Küche')->set('neu.kapazitaet', '480')->call('create')
        ->call('bearbeitenFertig');
    $html = $c->assertSeeHtml('data-fa-lesemodus="1"')->html();

    expect(LesemodusDom::bedienbar($html, '//*[@data-posten-zeile]'))->toBe([true])
        ->and(LesemodusDom::bedienbar($html, '//input[starts-with(@id, "posten-") and substring(@id, string-length(@id) - 4) = "-name"]'))->not->toBeEmpty()->each->toBeFalse();
});

it('DNA-Zusatzfelder: „Gilt für“ filtert im Lesemodus, Anlegen bleibt gesperrt', function () {
    $html = Livewire::test(DnaFelder::class)->assertSeeHtml('data-fa-lesemodus="1"')->html();

    expect(LesemodusDom::bedienbar($html, '//select[@data-dnaf-typ]'))->toBe([true])
        ->and(LesemodusDom::bedienbar($html, '//*[@data-fa-lesemodus="1"]//*[self::input or self::button]'))->not->toBeEmpty()->each->toBeFalse();
});

it('Einkauf: Lead-Vorschau lässt sich im Lesemodus schließen, die Strategie bleibt gesperrt', function () {
    $html = Livewire::test(Einkauf::class)->assertSeeHtml('data-fa-lesemodus="1"')->call('repickVorschau')->html();

    expect(LesemodusDom::bedienbar($html, '//*[@data-lead-repick-schliessen]'))->toBe([true])
        ->and(LesemodusDom::bedienbar($html, '//input[@type="radio" and @*[name()="wire:model.live"]="strategie"]'))->not->toBeEmpty()->each->toBeFalse();
});

it('Food-DNA: Reiter des Boards im Lesemodus bedienbar', function () {
    $html = Livewire::test(FoodDna::class)->assertSeeHtml('data-fa-lesemodus="1"')->html();

    expect(LesemodusDom::bedienbar($html, '//*[@data-canvas-reiter-tab]'))->not->toBeEmpty()->each->toBeTrue();
});

it('Soll-Ist-Abgleich: Auf/Zu bleibt unter einer gesperrten Lese-Sperre bedienbar', function () {
    $html = Blade::render('<x-fa::lese-sperre :an="true">@include(\'foodalchemist::livewire.planning.partials.coverage-panel\', [\'coverage\' => $c])</x-fa::lese-sperre>', [
        'c' => ['hat_geruest' => true, 'befunde' => [], 'zusammenfassung' => ['erfuellt' => 1, 'teilerfuellt' => 0, 'verletzt' => 0], 'ampel_gesamt' => 'erfuellt', 'wareneinsatz' => []],
    ]);

    expect(LesemodusDom::bedienbar($html, '//*[@data-coverage-toggle]'))->toBe([true]);
});

it('Handbuch-Panel: „Lücken prüfen“ bleibt im gesperrten Rahmen des Editors bedienbar', function () {
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Lesemodus-FB']);
    $html = LesemodusDom::inGesperrtemRahmen(Livewire::test(HandbuchPanel::class, ['type' => 'foodbook', 'quelleId' => $fb->id])->html());

    expect(LesemodusDom::bedienbar($html, '//*[@data-handbuch-pruefen]'))->toBe([true]);
});

it('Rezept eingebettet: „Verwendet in“ bleibt im gesperrten Rezept-Editor bedienbar', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Fond: Lesemodus');
    $eltern = $this->makeRecipe($this->rootTeam, 'Suppe: Lesemodus');
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $eltern->id, 'position' => 1,
        'raw_text' => 'Fond', 'quantity' => 1000, 'unit_vocab_id' => $g->id, 'referenced_recipe_id' => $basis->id, 'match_method' => 'recipe_ref']);
    $html = LesemodusDom::inGesperrtemRahmen(Livewire::test(RezeptDetailPanel::class, ['recipeId' => $basis->id, 'embedded' => true])->html());

    expect(LesemodusDom::bedienbar($html, '//*[@data-eltern-link]'))->toBe([true]);
});

it('Wächter: tiefe Anzeige-Knöpfe in Foodbook-Speisen und Speisekarten-Branding sind Anzeige-Knöpfe (kein <button>)', function () {
    $wurzel = dirname(__DIR__, 2).'/resources/views/livewire/';
    $fb = file_get_contents($wurzel.'foodbooks/index.blade.php');
    $sk = file_get_contents($wurzel.'speisekarte/index.blade.php');

    expect($fb)->toMatch('/<x-fa::anzeige-knopf[^>]*data-fb-format-edition-toggle/')
        ->and(substr_count($sk, 'icon="heroicon-m-clipboard-document" href="#" data-fa-anzeige x-on:click.prevent="navigator.clipboard'))->toBe(2);
});
