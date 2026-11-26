<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Exceptions\GpImGerichtException;
use Platform\FoodAlchemist\Livewire\Recipes\IngredientEditor;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Gericht\GerichtGpUmbauService;
use Platform\FoodAlchemist\Services\Pruefung\HardstopResolveService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\Reife\RecipeReifeAdapter;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Entscheid Dominique 17.11.2026: ein Gericht (is_sales_recipe) besteht nur aus Basisrezepten. Ein GP darf dort nicht
 * NEU als Zutat gesetzt werden — weder im Editor noch per MCP noch über Generator/Import. Altbestand bleibt editier- und
 * löschbar, Kopien nehmen ihn mit; der Reife-Befund gp_im_gericht zeigt ihn mit dem Umbau-Weg „Basisrezept anlegen".
 * Im Editor eines Gerichts taucht die GP-Auswahl gar nicht auf (keine sichtbare Sperre).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake']);
    $this->g = FoodAlchemistVocabEinheit::firstOrCreate(['team_id' => $this->rootTeam->id, 'slug' => 'g'],
        ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->tool = fn (string $name, array $args) => app(ToolRegistry::class)->get($name)->execute($args, $this->ctx);

    $this->gericht = $this->makeRecipe($this->rootTeam, '[HG] Senfrostbraten | Jus', ['status' => 'draft', 'is_sales_recipe' => true]);
    $this->jus = $this->makeRecipe($this->rootTeam, 'Jus: Altbier', ['status' => 'approved', 'yield_kg' => 1.0]);
    $this->kresse = $this->makeGp($this->rootTeam, 'Gartenkresse: frisch');
    $this->dill = $this->makeGp($this->rootTeam, 'Dill: frisch');
    $this->zeileJus = $this->makeIngredient($this->gericht, 'Jus: Altbier', null, '60', 1);
    $this->zeileJus->update(['referenced_recipe_id' => $this->jus->id, 'match_method' => 'recipe_ref']);
    // Altbestand: GP direkt im Gericht (wie die 16 Zeilen auf io)
    $this->alt = $this->makeIngredient($this->gericht, 'Gartenkresse', $this->kresse, '2', 2);
    $this->zeile = fn ($z) => ['id' => $z->id, 'gp_id' => $z->gp_id, 'referenced_recipe_id' => $z->referenced_recipe_id,
        'raw_text' => $z->raw_text, 'quantity' => (float) $z->quantity, 'unit_vocab_id' => $this->g->id, 'auto_ground' => false];
});

it('Service: eine NEUE GP-Zeile im Gericht wird typisiert abgelehnt, nichts wird geschrieben', function () {
    $vorher = $this->gericht->ingredients()->pluck('id')->all();

    try {
        app(RecipeService::class)->syncIngredients($this->rootTeam, $this->gericht->id, [
            ($this->zeile)($this->zeileJus->fresh()), ($this->zeile)($this->alt->fresh()),
            ['gp_id' => $this->dill->id, 'raw_text' => 'Dill', 'quantity' => 1, 'unit_vocab_id' => $this->g->id],
        ]);
        $this->fail('GpImGerichtException erwartet');
    } catch (GpImGerichtException $e) {
        expect($e->namen)->toBe(['Dill: frisch'])
            ->and($e->getMessage())->toBe('Ein Gericht enthält nur Basisrezepte. Lege für „Dill: frisch“ ein Basisrezept an (Zukauf oder Garnitur).');
    }

    expect($this->gericht->ingredients()->pluck('id')->all())->toBe($vorher)
        ->and($this->gericht->ingredients()->where('gp_id', $this->dill->id)->exists())->toBeFalse();
});

it('Service: eine Altbestand-Zeile darf nicht auf ein ANDERES GP wechseln', function () {
    expect(fn () => app(RecipeService::class)->syncIngredients($this->rootTeam, $this->gericht->id, [
        ($this->zeile)($this->zeileJus->fresh()), ['gp_id' => $this->dill->id] + ($this->zeile)($this->alt->fresh()),
    ]))->toThrow(GpImGerichtException::class);

    expect((int) $this->alt->fresh()->gp_id)->toBe((int) $this->kresse->id);
});

it('Service: Altbestand bleibt editierbar (Menge) und löschbar', function () {
    $zeile = ['quantity' => 5] + ($this->zeile)($this->alt->fresh());
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->gericht->id, [($this->zeile)($this->zeileJus->fresh()), $zeile]);
    expect((float) $this->alt->fresh()->quantity)->toBe(5.0)
        ->and((int) $this->alt->fresh()->gp_id)->toBe((int) $this->kresse->id);

    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->gericht->id, [($this->zeile)($this->zeileJus->fresh())]);
    expect($this->alt->fresh()->trashed())->toBeTrue();   // soft-gelöscht
});

it('Service: im Basisrezept bleiben GP-Zutaten erlaubt', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Garnitur: Kräuter', ['status' => 'draft']);
    app(RecipeService::class)->syncIngredients($this->rootTeam, $basis->id, [
        ['gp_id' => $this->dill->id, 'raw_text' => 'Dill', 'quantity' => 10, 'unit_vocab_id' => $this->g->id],
    ]);

    expect((int) $basis->ingredients()->value('gp_id'))->toBe((int) $this->dill->id);
});

it('Service: die Auto-Erdung legt im Gericht kein GP (eine neue Zeile bleibt offen oder wird Basisrezept)', function () {
    app(RecipeService::class)->syncIngredients($this->rootTeam, $this->gericht->id, [
        ($this->zeile)($this->zeileJus->fresh()), ($this->zeile)($this->alt->fresh()),
        ['raw_text' => 'Dill: frisch', 'display_name' => 'Dill: frisch', 'quantity' => 1, 'unit_vocab_id' => $this->g->id],
    ]);

    $neu = $this->gericht->ingredients()->where('position', 3)->first();
    expect($neu)->not->toBeNull()->and($neu->gp_id)->toBeNull();
});

it('MCP: recipe_ingredients.PUT lehnt neue GP-Zeile im Gericht mit GP_IM_GERICHT ab, Altbestand ohne id läuft durch', function () {
    $res = ($this->tool)('foodalchemist.recipe_ingredients.PUT', ['recipe_id' => $this->gericht->id, 'zutaten' => [
        ['name' => 'Jus: Altbier', 'referenced_recipe_id' => $this->jus->id, 'quantity' => 60, 'unit' => 'g'],
        ['name' => 'Dill', 'gp_id' => $this->dill->id, 'quantity' => 1, 'unit' => 'g'],
    ]]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('GP_IM_GERICHT')
        ->and($res->error)->toContain('Dill: frisch');

    // Voll-Sync wie ein Agent ohne ids: die Altbestand-Zeile trifft sich selbst und bleibt
    $ok = ($this->tool)('foodalchemist.recipe_ingredients.PUT', ['recipe_id' => $this->gericht->id, 'zutaten' => [
        ['name' => 'Jus: Altbier', 'referenced_recipe_id' => $this->jus->id, 'quantity' => 60, 'unit' => 'g'],
        ['name' => 'Gartenkresse', 'gp_id' => $this->kresse->id, 'quantity' => 3, 'unit' => 'g'],
    ]]);
    expect($ok->success)->toBeTrue($ok->error ?? '')
        ->and((float) $this->alt->fresh()->quantity)->toBe(3.0);
});

it('MCP: recipes.POST mit Gericht und GP-Zutat legt nichts an (GP_IM_GERICHT), Basisrezept mit GP geht', function () {
    $vorher = FoodAlchemistRecipe::query()->count();
    $res = ($this->tool)('foodalchemist.recipes.POST', ['name' => '[HG] Neues Gericht | Dill', 'is_sales_recipe' => true, 'zutaten' => [
        ['name' => 'Dill', 'gp_id' => $this->dill->id, 'quantity' => 1, 'unit' => 'g'],
    ]]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('GP_IM_GERICHT')
        ->and(FoodAlchemistRecipe::query()->count())->toBe($vorher);

    $basis = ($this->tool)('foodalchemist.recipes.POST', ['name' => 'Garnitur: Dill', 'zutaten' => [
        ['name' => 'Dill', 'gp_id' => $this->dill->id, 'quantity' => 1, 'unit' => 'g'],
    ]]);
    expect($basis->success)->toBeTrue($basis->error ?? '');
});

it('Kopie eines Gerichts mit Altbestand scheitert nicht und nimmt die GP-Zeile mit', function () {
    $kopie = app(RecipeService::class)->duplicate($this->rootTeam, $this->gericht->id, '[HG] Senfrostbraten | Jus (Kopie)');

    expect($kopie->is_sales_recipe)->toBeTrue()
        ->and($kopie->ingredients()->where('gp_id', $this->kresse->id)->exists())->toBeTrue();
});

it('Hard-Stop: eine offene Gericht-Zeile lässt sich nicht auf ein GP legen (ok=false, Code), nur auf ein Basisrezept', function () {
    $offen = $this->makeIngredient($this->gericht, 'Dill', null, '1', 3);
    $r = app(HardstopResolveService::class)->verknuepfe($this->rootTeam, $this->gericht->id, 3, 'gp', $this->dill->id);

    expect($r['ok'])->toBeFalse()->and($r['code'] ?? null)->toBe('GP_IM_GERICHT')
        ->and($offen->fresh()->gp_id)->toBeNull();
});

it('Reife: Befund gp_im_gericht je Gericht mit direkter GP-Zeile; Basisrezept anlegen hängt die Zeile um', function () {
    $luecke = collect(app(RecipeReifeAdapter::class)->messe($this->rootTeam, $this->gericht->id)['luecken'])->firstWhere('code', 'gp_im_gericht');
    expect($luecke)->not->toBeNull()
        ->and($luecke['was'])->toContain('GP direkt im Gericht — Basisrezept anlegen')->toContain('Gartenkresse: frisch')
        ->and($luecke['wie']['tool'])->toBe('foodalchemist.recipe_gp_zu_basisrezept.POST');

    $r = app(GerichtGpUmbauService::class)->basisrezeptAnlegen($this->rootTeam, $this->alt->id, 'Garnitur-Basis: Gartenkresse');

    $basis = FoodAlchemistRecipe::query()->findOrFail($r['recipe_id']);
    $zeile = $this->alt->fresh();
    expect($r['neu'])->toBeTrue()
        ->and($basis->is_sales_recipe)->toBeFalse()
        ->and($basis->status->value)->toBe('draft')
        ->and($basis->name)->toBe('Garnitur-Basis: Gartenkresse')
        ->and((int) $basis->ingredients()->value('gp_id'))->toBe((int) $this->kresse->id)
        ->and((float) $basis->ingredients()->value('quantity'))->toBe(2.0)
        ->and($zeile->gp_id)->toBeNull()
        ->and((int) $zeile->referenced_recipe_id)->toBe((int) $basis->id)
        ->and((float) $zeile->quantity)->toBe(2.0);

    $nachher = app(RecipeReifeAdapter::class)->messe($this->rootTeam, $this->gericht->id);
    expect(array_column($nachher['luecken'], 'code'))->not->toContain('gp_im_gericht')
        ->and($nachher['erfuellt'])->toContain('gp_im_gericht');
});

it('Basisrezept anlegen: Name-Vorschlag mit Typ-Präfix; ohne ableitbaren Typ nur Vorschlag „Topping"', function () {
    $v = app(GerichtGpUmbauService::class)->nameVorschlag($this->alt->fresh());

    expect($v['name'])->toStartWith('Topping: ')->toContain('Gartenkresse')
        ->and($v['typ_sicher'])->toBeFalse();
});

it('MCP: recipe_gp_zu_basisrezept.POST baut die Zeile um, ein zweiter Aufruf auf dieselbe Zeile wird abgelehnt', function () {
    $res = ($this->tool)('foodalchemist.recipe_gp_zu_basisrezept.POST', ['ingredient_id' => $this->alt->id, 'name' => 'Topping: Gartenkresse']);
    expect($res->success)->toBeTrue($res->error ?? '')
        ->and($res->data['basisrezept']['name'])->toBe('Topping: Gartenkresse')
        ->and($this->alt->fresh()->referenced_recipe_id)->not->toBeNull();

    $nochmal = ($this->tool)('foodalchemist.recipe_gp_zu_basisrezept.POST', ['ingredient_id' => $this->alt->id]);
    expect($nochmal->success)->toBeFalse()->and($nochmal->errorCode)->toBe('VALIDATION_ERROR');
});

it('Editor im Gericht: kein GP-Treffer und kein GP-Bedienelement im HTML — im Basisrezept-Editor schon', function () {
    $basis = $this->makeRecipe($this->rootTeam, 'Garnitur: Kräuter', ['status' => 'draft']);

    $gerichtEditor = Livewire::test(IngredientEditor::class, ['recipeId' => $this->gericht->id, 'eingebettet' => true]);
    $html = $gerichtEditor->html();
    expect($html)->not->toContain('data-einfuegen="gp"')
        ->not->toContain('aria-label="Grundprodukte einfügen"')
        ->not->toContain('data-gp-filter-wg')
        ->not->toContain('Keine Grundprodukte gefunden')
        ->toContain('data-einfuegen="rez"');
    $katalog = $gerichtEditor->instance()->browseKatalog([], [], 'Dill');
    expect($katalog['gps'])->toBe(['items' => [], 'total' => 0])
        ->and(collect(app(RecipeService::class)->sucheZutatenZiel($this->rootTeam, 'Dill', $this->gericht->id))->where('type', 'gp')->all())->toBe([]);

    $basisEditor = Livewire::test(IngredientEditor::class, ['recipeId' => $basis->id, 'eingebettet' => true]);
    expect($basisEditor->html())->toContain('data-einfuegen="gp"')->toContain('data-gp-filter-wg');
    expect($basisEditor->instance()->browseKatalog([], [], 'Dill')['gps']['total'])->toBe(1)
        ->and(collect(app(RecipeService::class)->sucheZutatenZiel($this->rootTeam, 'Dill', $basis->id))->where('type', 'gp')->count())->toBe(1);
});

it('VK-Modal: Altbestand-Befund mit editierbarem Namen und Knopf „Basisrezept anlegen"', function () {
    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    expect($c->html())->toContain('data-gp-im-gericht')->toContain('GP direkt im Gericht — Basisrezept anlegen');

    $c->set("gpUmbauNamen.{$this->alt->id}", 'Topping: Kresse')->call('gpZeileAlsBasisrezept', $this->alt->id)->assertSet('fehler', null);

    expect($this->alt->fresh()->gp_id)->toBeNull()
        ->and(FoodAlchemistRecipe::query()->where('name', 'Topping: Kresse')->where('is_sales_recipe', false)->exists())->toBeTrue()
        ->and($c->html())->not->toContain('data-gp-im-gericht-zeile');
});
