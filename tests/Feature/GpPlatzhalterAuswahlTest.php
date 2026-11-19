<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->wasser = $this->makeGp($this->rootTeam, 'Leitungswasser');
    $this->platz = app(GpService::class)->createPlatzhalter($this->rootTeam, 'Leitungswasser');
});

it('bietet neue Platzhalter in beiden GP-Tauschsuchen an und markiert sie erkennbar', function () {
    foreach ([GpModal::class, DetailPanel::class] as $class) {
        $lw = Livewire::test($class);
        $lw->call($class === GpModal::class ? 'oeffnen' : 'zeige', $this->wasser->id)
            ->set('tauschSuche', 'Leitungswasser')
            ->assertViewHas('tauschKandidaten', fn ($k) => $k->pluck('id')->all() === [$this->platz->id]);
        if ($class === GpModal::class && ($p = getenv('FA_GP_TAUSCH_PREVIEW_PATH'))) file_put_contents($p, $lw->html());
    }
    Livewire::test(GpModal::class)->call('oeffnen', $this->wasser->id)->set('tauschSuche', 'Leitungswasser')
        ->assertSee('Platzhalter')->assertSee('Lieferantenartikel bleiben am bisherigen Grundprodukt.');
});

it('findet und speichert auch einen erst nach Öffnen angelegten Platzhalter als Basisrezept-Zutat', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'Sauce');
    $lw = Livewire::test(IngredientEditor::class, ['recipeId' => $rezept->id, 'eingebettet' => true]);
    $neu = app(GpService::class)->createPlatzhalter($this->rootTeam, 'Neue Flüssigkeit');
    $liste = $lw->instance()->browseKatalog([], [], 'Neue Flüssigkeit');
    expect(array_column($liste['gps']['items'], 'id'))->toBe([$neu->id])
        ->and($liste['gps']['items'][0]['is_platzhalter'])->toBeTrue();
    expect(array_column($lw->instance()->sucheZiel('Neue Flüssigkeit'), 'id'))->toContain($neu->id);
    $lw->call('speichern', [['raw_text' => $neu->name, 'quantity' => '120',
        'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'gp_id' => $neu->id, 'match_method' => 'manual']], $rezept->id)
        ->assertSet('fehler', null);
    expect($rezept->ingredients()->first()->gp_id)->toBe($neu->id);
});

it('belässt beim Tausch auf einen Platzhalter Lieferantenartikel an der Quelle', function () {
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Wasserlieferant']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id,
        'designation' => 'Wasser', 'article_number' => 'wasser', 'qty' => 1, 'unit_code' => 'l']);
    $struktur = FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $la->id, 'gp_id' => $this->wasser->id]);
    $rezept = $this->makeRecipe($this->rootTeam, 'Wasserrezept');
    $zeile = $this->makeIngredient($rezept, 'Wasser', $this->wasser, '120');
    $r = app(GpService::class)->ersetzeKomplett($this->rootTeam, $this->wasser, $this->platz);
    expect($r['zeilen'])->toBe(1)->and($r['artikel_umgehaengt'])->toBe(0)
        ->and($zeile->fresh()->gp_id)->toBe($this->platz->id)
        ->and($struktur->fresh()->gp_id)->toBe($this->wasser->id)
        ->and($this->platz->structures()->count())->toBe(0);
});

it('öffnet geerbte Grundprodukte erkennbar schreibgeschützt ohne Speichern freizugeben', function () {
    $this->actingAs($this->makeUser($this->childA));
    $panel = Livewire::test(DetailPanel::class, ['gpId' => $this->wasser->id]);
    if ($p = getenv('FA_GP_LESEN_PREVIEW_PATH')) file_put_contents($p, $panel->html());
    $panel->assertSeeHtml('data-gp-ansehen')
        ->assertSeeHtml('data-gp-geerbt')->assertDontSeeHtml('data-gp-bearbeiten');
    Livewire::test(GpModal::class)->call('oeffnen', $this->wasser->id)->assertSee('Grundprodukt ansehen')
        ->assertDontSeeHtml('data-gp-speichern-kopf')->set('manuellerName', 'Fremd geändert')->call('speichern')
        ->assertSet('fehler', fn ($f) => is_string($f) && str_contains($f, 'Besitzer-Team'));
    expect($this->wasser->fresh()->name)->toBe('Leitungswasser');
});

it('blendet fremde, abgelehnte und Non-Food-Tauschziele weiterhin aus', function () {
    $fremd = app(GpService::class)->createPlatzhalter($this->childB, 'Leitungswasser fremd');
    $nonfood = $this->makeGp($this->rootTeam, 'Leitungswasser Gefäß');
    $nonfood->update(['commodity_group_code' => '16']);
    $abgelehnt = $this->makeGp($this->rootTeam, 'Leitungswasser abgelehnt');
    $abgelehnt->update(['status' => 'rejected']);
    Livewire::test(GpModal::class)->call('oeffnen', $this->wasser->id)->set('tauschSuche', 'Leitungswasser')
        ->assertViewHas('tauschKandidaten', fn ($k) => $k->pluck('id')->all() === [$this->platz->id]);
    expect(FoodAlchemistGp::visibleToTeam($this->rootTeam)->find($fremd->id))->toBeNull();
    expect(fn () => app(GpService::class)->ersetzeKomplett($this->rootTeam, $this->wasser, $fremd))
        ->toThrow(RuntimeException::class, 'nicht sichtbar');
});
