<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\DataQualityService;
use Platform\FoodAlchemist\Services\GpStamm\StueckOhneGewicht;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\RecipeIngredientsPutTool;
use Platform\FoodAlchemist\Tools\RecipesGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Entscheid Dominique 23.11. zu (a): Zutaten in Stück- bzw. Zähl-Einheit ohne Gewicht werden gespeichert, aber gemeldet — im
 * Zutaten-Editor, an der Darreichung und per MCP — und stehen in der Pflegeliste der Datenqualität (Kennzahl gp_stueck_ohne_gewicht).
 * Gepflegt wird das Stückgewicht am Grundprodukt; danach verschwindet der Hinweis.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $t = $this->rootTeam->id;
    $this->stk = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count']);
    $this->ei = $this->makeGp($this->rootTeam, 'Ei');
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Eierhof']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $lieferant->id, 'designation' => 'Eier 10 Stk',
        'article_number' => 'EI-10', 'qty' => 10.0, 'unit_code' => 'Stk', 'packaging_unit' => 'Karton']);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $this->ei->id]);
    FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => 3.00, 'status' => '0']);
    $this->ei->update(['lead_la_supplier_item_id' => $la->id, 'piece_default_g' => null]);
    $this->linsen = ($this->gp)('Belugalinsen', 4.00);

    // Basisrezept mit 1 Ei ohne Stückgewicht
    [$this->basis] = ($this->rezept)('eiersalat', 'Eiersalat', [[$this->linsen, 200]], ['is_sales_recipe' => false]);
    $this->eiZeile = $this->basis->ingredients()->create(['team_id' => $t, 'position' => 9, 'gp_id' => $this->ei->id, 'raw_text' => 'Ei',
        'quantity' => 1, 'unit_vocab_id' => $this->stk->id, 'match_method' => 'manual']);
    app(RecipeRecomputeService::class)->recomputePipeline($this->basis->id);
    $this->ctx = new ToolContext($this->makeUser($this->rootTeam, 'MCP'), $this->rootTeam);
});

it('Pflegeliste: Kennzahl zählt und listet das Grundprodukt; mit Stückgewicht verschwindet es, eine optionale Zeile zählt nicht', function () {
    $dq = app(DataQualityService::class);
    $gpMetrik = fn () => collect($dq->messeAlleEbenen($this->rootTeam)['gp']['metriken'])->firstWhere('key', StueckOhneGewicht::KEY);

    expect($gpMetrik())->toMatchArray(['wert' => 1, 'severity' => 'info', 'label' => StueckOhneGewicht::LABEL])
        ->and(collect($dq->betroffene($this->rootTeam, StueckOhneGewicht::KEY))->pluck('id')->all())->toBe([$this->ei->id])
        ->and($dq->metrikLabel(StueckOhneGewicht::KEY))->toBe(StueckOhneGewicht::LABEL)
        ->and($dq->trifftObjekt($this->rootTeam, StueckOhneGewicht::KEY, 'gp', $this->ei->id))->toBeTrue();

    $this->eiZeile->update(['is_optional' => true]);
    expect($gpMetrik()['wert'])->toBe(0);

    $this->eiZeile->update(['is_optional' => false]);
    $this->ei->update(['piece_default_g' => 60]);
    expect($gpMetrik()['wert'])->toBe(0)->and($gpMetrik()['severity'])->toBe('gruen');
});

it('Zutaten-Editor: Speichern geht durch, danach Hinweis mit Pflege-Link; mit Stückgewicht kein Hinweis', function () {
    $zeilen = fn () => [
        ['id' => null, 'gp_id' => $this->linsen->id, 'raw_text' => '200 g Belugalinsen', 'quantity' => '200', 'unit_vocab_id' => $this->g->id],
        ['id' => null, 'gp_id' => $this->ei->id, 'raw_text' => '1 Ei', 'quantity' => '1', 'unit_vocab_id' => $this->stk->id],
    ];
    $editor = Livewire::test(IngredientEditor::class)->call('oeffnen', $this->basis->id)->call('speichern', $zeilen())
        ->assertSet('fehler', null)
        ->assertDispatched('recipe-gespeichert');

    expect($editor->get('ohneGewicht'))->toHaveCount(1)
        ->and($editor->get('ohneGewicht')[0])->toMatchArray(['gp_id' => $this->ei->id, 'menge' => 1.0, 'einheit' => 'Stück'])
        ->and($editor->html())->toContain('data-editor-ohne-gewicht')->toContain('data-ohne-gewicht-gp="' . $this->ei->id . '"')
        ->and($this->basis->fresh()->ingredients()->count())->toBe(2);   // gespeichert trotz Hinweis

    $this->ei->update(['piece_default_g' => 60]);
    $editor->call('speichern', $zeilen())->assertSet('ohneGewicht', []);
    expect($editor->html())->not->toContain('data-editor-ohne-gewicht');
});

it('Darreichung: Standard „Summe“ meldet die Zeile ohne Gewicht, eine Variante mit Anteil ist vorläufig', function () {
    [$gericht] = ($this->rezept)('ei-gericht', '[HG] Linsen | Ei', [[$this->linsen, 200]]);
    $gericht->ingredients()->create(['team_id' => $this->rootTeam->id, 'position' => 9, 'gp_id' => $this->ei->id, 'raw_text' => 'Ei',
        'quantity' => 1, 'unit_vocab_id' => $this->stk->id, 'match_method' => 'manual']);
    app(RecipeRecomputeService::class)->recomputePipeline($gericht->id);
    $std = ($this->darreichung)($gericht, 'teller', ['is_standard' => true, 'menge_vom_standard' => true]);
    $svc = app(DarreichungService::class);
    $svc->recomputeFuerRezept($gericht->id);

    $h = StueckOhneGewicht::darreichung($gericht->fresh(), $std->fresh(), $this->rootTeam);
    expect($h['vorlaeufig'])->toBeFalse()->and($h['text'])->toStartWith('Ohne Gewicht: Ei (1 Stück)');

    $v = $svc->setzeAnteil($this->rootTeam, $svc->anlegen($this->rootTeam, $gericht->id, ($this->form)('klein')->id)->id, 50);
    expect(StueckOhneGewicht::darreichung($gericht->fresh(), $v, $this->rootTeam)['text'])->toStartWith('EK vorläufig: Ei (1 Stück)');

    $html = Livewire::test(VkModal::class)->call('oeffnen', $gericht->id)->html();
    expect($html)->toContain('data-dar-ohne-gewicht')->toContain('Ohne Gewicht: Ei (1 Stück)');
});

it('MCP im Lockstep: recipes.GET und recipe_ingredients.PUT melden zutaten_ohne_gewicht, nach der Pflege nicht mehr', function () {
    $get = fn () => app(RecipesGetTool::class)->execute(['id' => $this->basis->id], $this->ctx);
    expect($get()->data['zutaten_ohne_gewicht'] ?? null)->toHaveCount(1)
        ->and($get()->data['zutaten_ohne_gewicht'][0])->toMatchArray(['ingredient_id' => $this->eiZeile->id, 'gp_id' => $this->ei->id, 'einheit' => 'Stück']);

    $put = app(RecipeIngredientsPutTool::class)->execute(['recipe_id' => $this->basis->id, 'zutaten' => [
        ['id' => $this->eiZeile->id, 'name' => 'Ei', 'gp_id' => $this->ei->id, 'quantity' => 2, 'unit' => 'stk'],
    ]], $this->ctx);
    expect($put->success)->toBeTrue()
        ->and($put->data['zutaten_ohne_gewicht'][0]['menge'] ?? null)->toBe(2.0);

    $this->ei->update(['piece_default_g' => 60]);
    expect($get()->data)->not->toHaveKey('zutaten_ohne_gewicht');
});
