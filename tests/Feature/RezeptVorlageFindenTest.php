<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Services\Rezept\RecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, Benennung wie 2.4f: Basisrezept-Vorlagen sind leicht zu finden. Der Leerzustand unter „Aus Vorlage“ nennt den
 * echten Weg (Rezept öffnen → Menü ⋯ „Weitere Aktionen“ → „Als Vorlage speichern“). Editor und Detailspalte benutzen
 * dasselbe Symbol und denselben Wortlaut wie der Konzepter: square-2-stack, „Als Vorlage speichern“ / „Vorlage aufheben“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    $this->rezept = app(RecipeService::class)->create($this->rootTeam, ['name' => 'Fond: Vorlage']);
});

it('Leerzustand „Aus Vorlage“ nennt Menü ⋯ „Weitere Aktionen“ und „Als Vorlage speichern“', function () {
    $html = Livewire::test(Browser::class)->set('templateWahlOffen', true)->html();

    expect($html)->toContain('data-template-leer')
        ->toContain('Noch keine Vorlagen')
        ->toContain('Rezept öffnen, dann im Menü ⋯ (Weitere Aktionen) „Als Vorlage speichern“ wählen.')
        ->not->toContain('„Als Vorlage" markieren');
});

it('Detailspalte: square-2-stack und „Als Vorlage speichern“, nach dem Speichern „Vorlage aufheben“', function () {
    $lw = Livewire::test(DetailPanel::class)->dispatch('recipe-selected', id: $this->rezept->id);
    $html = $lw->html();

    expect($html)->toContain('Als Vorlage speichern')
        ->not->toContain('Als Vorlage markieren')->not->toContain('Vorlage-Markierung')
        ->not->toContain('heroicon-o-star')->not->toContain('heroicon-s-star')->not->toContain('heroicon-m-star');

    $lw->call('templateToggle');
    expect($this->rezept->fresh()->is_template)->toBeTrue()
        ->and($lw->html())->toContain('Vorlage aufheben')->not->toContain('Als Vorlage speichern');
});

it('Editor: „Weitere Aktionen“ führt „Als Vorlage speichern“ bzw. „Vorlage aufheben“', function () {
    $lw = Livewire::test(RecipeModal::class)->call('oeffnen', $this->rezept->id);

    expect($lw->html())->toContain('Weitere Aktionen')->toContain('Als Vorlage speichern')->not->toContain('Als Vorlage markieren');

    $lw->call('templateToggle')->call('oeffnen', $this->rezept->id);
    expect($lw->html())->toContain('Vorlage aufheben');
});

it('Wächter: Rezept-Views kennen nur noch einen Wortlaut und ein Symbol für Vorlagen', function () {
    $verstoesse = [];
    foreach (glob(dirname(__DIR__, 2).'/resources/views/livewire/recipes/*.blade.php') as $datei) {
        $inhalt = (string) preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($datei));   // Blade-Kommentare zählen nicht
        foreach (['Als Vorlage markieren', 'Vorlage-Markierung', '„Als Vorlage"'] as $alt) {
            if (str_contains($inhalt, $alt)) {
                $verstoesse[] = basename($datei).': '.$alt;
            }
        }
        foreach (explode("\n", $inhalt) as $zeile) {
            if (str_contains($zeile, 'templateToggle') && str_contains($zeile, 'star')) {
                $verstoesse[] = basename($datei).': Stern an templateToggle';
            }
        }
    }

    expect($verstoesse)->toBe([]);
});
