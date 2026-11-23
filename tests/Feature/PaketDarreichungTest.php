<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\PaketGerichteSetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 2026-10-08: Paket-Gerichte behalten beim Hinzufügen/Entfernen ihre Menge, Einheit,
 * Darreichung (vorher: alle Zeilen gelöscht und neu angelegt), und die
 * Darreichung ist im Paket-Editor je Posten wählbar.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $this->gericht = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'bratkartoffeln', 'name' => 'Bratkartoffeln', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 4.00, 'ek_total_eur' => 2.00]);
    $this->zweites = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'rahmspinat', 'name' => 'Rahmspinat', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.00, 'ek_total_eur' => 1.00]);
    $teller = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $kind = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'kinder', 'label' => 'Kinder']);
    $this->standard = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $teller->id, 'is_standard' => true, 'quantity_per_unit_g' => 300, 'unit_count' => 1, 'ek_portion' => 2.00, 'sales_net' => 4.00, 'price_mode' => 'auto']);
    $this->kinder = FoodAlchemistRecipeDarreichung::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'serving_form_id' => $kind->id, 'is_standard' => false, 'quantity_per_unit_g' => 150, 'unit_count' => 1, 'ek_portion' => 1.00, 'sales_net' => 2.50, 'price_mode' => 'auto']);

});

it('MCP paket_gerichte.SET: presentation_id setzen, weglassen erhält, Payload liefert die Form', function () {
    // Seit 2026-11-12 arbeitet paket_gerichte.SET auf Concepter-Paketen (kind=paket-Concepts).
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $tool = app(PaketGerichteSetTool::class);
    $paket = app(\Platform\FoodAlchemist\Services\Concepter\ConceptPaketService::class)->create($this->rootTeam, ['name' => 'Beilagen-Duo']);
    $tool->execute(['paket_id' => $paket->id, 'items' => [['sales_recipe_id' => $this->gericht->id, 'quantity' => 1]]], $ctx);

    $r = $tool->execute(['paket_id' => $paket->id, 'items' => [['sales_recipe_id' => $this->gericht->id, 'presentation_id' => $this->kinder->id]]], $ctx);
    expect($r->success)->toBeTrue('set: ' . ($r->error ?? ''))
        ->and($r->data['paket']['dishes'][0]['presentation_id'])->toBe($this->kinder->id)
        ->and($r->data['paket']['dishes'][0]['darreichung'])->toBe('Kinder')
        ->and($r->data['paket']['dishes'][0]['quantity'])->toBe(1.0);          // Menge blieb erhalten

    $r = $tool->execute(['paket_id' => $paket->id, 'items' => [['sales_recipe_id' => $this->gericht->id], ['sales_recipe_id' => $this->zweites->id]]], $ctx);
    expect($r->data['paket']['dishes'][0]['presentation_id'])->toBe($this->kinder->id);

    $r = $tool->execute(['paket_id' => $paket->id, 'items' => [['sales_recipe_id' => $this->gericht->id, 'presentation_id' => 0]]], $ctx);
    expect($r->data['paket']['dishes'][0]['presentation_id'])->toBeNull();
});
