<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\Browser as GpBrowser;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\GpService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98, 2.4e (U5): Kopf-KPIs und Tabellen ohne Widerspruch.
 * Foodbook: Format-Editionen als „ab …“ statt „Preis fehlt“ (neues Feld edition_ab, bestehende Felder unverändert).
 * Rezept/Gericht: Tabellensumme ungerundet addiert, vor dem Speichern „vorläufig“, Beschriftung „Summe Ansatz“.
 * GP-Browser: Status-Pill zählt mit Suche und Warengruppe.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->fb = app(FoodbookService::class);
});

function formatMitEditionen(int $teamId, array $preise): FoodAlchemistFormat
{
    $format = FoodAlchemistFormat::create(['team_id' => $teamId, 'name' => 'Sommer-Gala', 'status' => 'aktiv']);
    foreach (array_values($preise) as $i => $preis) {
        $c = FoodAlchemistConcept::create(['team_id' => $teamId, 'name' => 'Edition ' . $i, 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => $preis]);
        FoodAlchemistFormatSlot::create(['team_id' => $teamId, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $c->id, 'position' => $i]);
    }

    return $format;
}

it('Foodbook gesamt(): bestehende Felder unverändert, edition_ab neu — nur Format-Kapitel ergibt VK 0 und „ab“ die kleinste Edition', function () {
    $buch = $this->fb->create($this->rootTeam, ['label' => 'Gala', 'personen' => 100]);
    $kap = $this->fb->addKapitel($this->rootTeam, $buch->id, ['title' => 'Editionen']);
    FoodAlchemistFoodbookKapitel::whereKey($kap->id)->update(['format_id' => formatMitEditionen($this->rootTeam->id, [34.0, 28.5, 0.0])->id]);

    $g = $this->fb->gesamt($this->rootTeam, $buch->fresh());

    expect(array_keys($g))->toBe(['vk_pro_person', 'ek_per_person', 'pauschal', 'personen', 'gesamt_vk', 'gesamt_ek', 'food_cost_percent', 'vk_fehlt', 'edition_ab'])
        ->and($g['vk_pro_person'])->toBe(0.0)          // Editionen sind Alternativen — zählen nicht in die Summe (unverändert)
        ->and($g['gesamt_vk'])->toBe(0.0)
        ->and($g['edition_ab'])->toBe(28.5);            // 0,00 € zählt nicht als Preis
});

it('Foodbook gesamt(): gemischt bleibt die Summe gleich, edition_ab kommt dazu; ohne Format null', function () {
    $buch = $this->fb->create($this->rootTeam, ['label' => 'Gala', 'personen' => 10]);
    $menue = $this->fb->addKapitel($this->rootTeam, $buch->id, ['title' => 'Menü']);
    $this->fb->addBlock($this->rootTeam, $menue->id, ['type' => 'header_frei_preis', 'label' => 'Aperitif', 'price_basis' => 'person', 'price_value' => 20]);

    $ohne = $this->fb->gesamt($this->rootTeam, $buch->fresh());
    expect($ohne['vk_pro_person'])->toBe(20.0)->and($ohne['gesamt_vk'])->toBe(200.0)->and($ohne['edition_ab'])->toBeNull();

    $kap = $this->fb->addKapitel($this->rootTeam, $buch->id, ['title' => 'Editionen']);
    FoodAlchemistFoodbookKapitel::whereKey($kap->id)->update(['format_id' => formatMitEditionen($this->rootTeam->id, [12.0, 15.0])->id]);
    $mit = $this->fb->gesamt($this->rootTeam, $buch->fresh());

    expect(array_diff_key($mit, ['edition_ab' => 1]))->toBe(array_diff_key($ohne, ['edition_ab' => 1]))
        ->and($mit['edition_ab'])->toBe(12.0);
});

it('Foodbook-Detail: Kopf zeigt „ab … je Edition“ statt „Preis fehlt“, gemischt „zzgl. Edition ab …“', function () {
    $quelle = file_get_contents(__DIR__ . '/../../resources/views/livewire/foodbooks/partials/detail.blade.php');

    expect($quelle)->toContain("\$editionAb = \$gesamt['edition_ab'] ?? null;")
        ->toContain("'label' => 'VK/Person je Edition', 'value' => 'ab ' . \$euro(\$editionAb)")
        ->toContain("'zzgl. Edition ab ' . \$euro(\$editionAb)");
});

it('Zutaten-Tabelle: Summe ungerundet addiert, „vorläufig“ bis zum Speichern, Beschriftung „Summe Ansatz“', function () {
    $editor = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/ingredient-editor.blade.php');
    $kern = file_get_contents(__DIR__ . '/../../resources/views/livewire/recipes/partials/zutaten-kern.blade.php');

    preg_match('/summe\(feld = \'ek_pro_g\'\) \{(.*?)\n            \},/s', $editor, $m);
    expect($m[1] ?? '')->toContain('this.zeilenEkWert(z, feld)')->toContain('s += w;')
        ->not->toContain('parseFloat')                                          // keine gerundeten Zeilenstrings mehr addieren
        ->and($editor)->toContain("zeilenEk(z, feld = 'ek_pro_g') {")             // Zeilen bleiben auf 2 Stellen
        ->toContain("return w === null ? null : w.toFixed(2).replace('.', ',') + ' €';")
        ->toContain('this.geladen = JSON.stringify(this.payload());')
        ->toContain("window.addEventListener('zutaten-persistiert'")
        ->toContain('vorlaeufig() { return this.geladen !== null && JSON.stringify(this.payload()) !== this.geladen; }')
        ->and($kern)->toContain('x-show="vorlaeufig()"')->toContain('vorläufig, noch nicht gespeichert')->toContain('Summe Ansatz');
});

it('Zutaten-Speichern meldet zutaten-persistiert — daran hängt „vorläufig“', function () {
    expect(file_get_contents(__DIR__ . '/../../src/Livewire/Recipes/IngredientEditor.php'))
        ->toContain("\$this->dispatch('zutaten-persistiert', recipeId: \$this->recipeId);");
});

it('GP-Browser: Status-Zähler folgen Suche und Warengruppe, nicht dem eigenen Status-Filter', function () {
    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '01', 'name' => 'Gemüse']);
    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '07', 'name' => 'Milchprodukte']);
    $mk = function (string $name, string $wg, string $status) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['commodity_group_code' => $wg, 'status' => $status]);
    };
    $mk('Butter: frisch', '07', 'approved');
    $mk('Butterschmalz', '07', 'tentative');
    $mk('Karotte: frisch', '01', 'approved');
    $mk('Lauch: frisch', '01', 'approved');
    $svc = app(GpService::class);

    expect($svc->statusCounts($this->rootTeam, ['search' => 'butter', 'commodity_group' => '07', 'sub_category' => '', 'status' => 'approved']))
        ->toEqual(['approved' => 1, 'tentative' => 1])
        ->and($svc->statusCounts($this->rootTeam, ['search' => '', 'commodity_group' => '01', 'sub_category' => '', 'status' => '']))->toEqual(['approved' => 2]);

    $html = Livewire::test(GpBrowser::class)->set('search', 'butter')->html();
    expect($html)->toContain('Alle 2')->not->toContain('Alle 4');
});
