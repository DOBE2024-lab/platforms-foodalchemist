<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\Produktion\GefaessKostenartService;
use Platform\FoodAlchemist\Services\Produktion\GefaessZubehoerService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpGefaessZubehoerPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Zubehör am Gefäß-GP (Entscheid Dominique 20.11.2026, „Weg 2“): am Glas hängt „gehört dazu: n × Deckel“, jede Darreichung mit dem Glas rechnet
 * das Zubehör mit — jedes Teil mit seinem Preis und seiner Kostenart. Pilotfall: Mietglas (extern, 0,28 € je Einsatz) + gekaufter Einweg-Deckel
 * (Artikel 0,84 € je 12 Stk = 0,07 €/Stk). Gericht 10 €/kg × 200 g ⇒ Lebensmittel-EK 2,00 €.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->svc = app(DarreichungService::class);
    $this->arten = app(GefaessKostenartService::class);
    $this->zub = app(GefaessZubehoerService::class);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    // Non-Food-GP mit Lead-Artikel (Gebinde 12 Stk), optional bepreist
    $this->nonFood = function (string $name, ?float $gebindePreis) {
        $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'gz|'.mb_strtolower($name), 'name' => $name, 'status' => 'approved',
            'commodity_group_code' => '16', 'sub_category' => NonFoodRegeln::SUB_GESCHIRR]);
        $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Verpackungs-Großhandel']);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => $name, 'qty' => 12.0, 'unit_code' => 'Stk']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        if ($gebindePreis !== null) {
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $gebindePreis, 'status' => '0']);
        }
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return $gp->refresh();
    };
    $this->gericht = function (string $key = 'dessert') {
        $g = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => 'Dessert '.$key, 'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 10.0, 'yield_kg' => 1.0]);
        $d = $this->svc->ensureStandard($this->rootTeam, $g->id);

        return $this->grammaturAltbestand($d, 200, 1)->fresh();   // Plan 98 B: Grammatur als Altbestand-Fixture (freie Grammatur ist über den Service gesperrt)
    };
    $this->glas = ($this->nonFood)('Achenbachglas: Glas, 212 ml', 6.00);        // Artikel 0,50 €/Stk, als Miete 0,28 € am GP
    $this->deckel = ($this->nonFood)('Deckel: Twist-off, 82 mm', 0.84);           // 0,07 €/Stk
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'extern', '0,28');
    $this->dar = ($this->gericht)();
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $this->ek = fn ($d = null) => (float) ($d ?? $this->dar)->fresh()->ek_portion;
});

it('Gegenprobe: ohne Zubehör rechnet das Gefäß exakt wie bisher (Mietglas 0,28 €)', function () {
    $g = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.28)->and($g['kosten'])->toBe(0.28)->and($g['zubehoer'])->toBe([])->and($g['zubehoer_summe'])->toBe(0.0)
        ->and($g['zubehoer_preis_fehlt'])->toBeFalse()->and($g['gesamt'])->toBe(0.28)
        ->and($this->svc->beigabenKosten($this->dar->fresh(), $this->rootTeam))->toMatchArray(['summe' => 0.28, 'vollstaendig' => true]);
});

it('Mietglas (extern) + Einweg-Deckel: jedes Teil mit eigenem Preis und eigener Art, die Darreichung rechnet automatisch mit', function () {
    $n = $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);

    $g = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam);
    expect($n)->toBe(1)->and(($this->ek)())->toBe(2.35)   // 2,00 + 0,28 Glas (extern, Preis am GP) + 0,07 Deckel (einweg, Artikelpreis)
        ->and($g['kostenart'])->toBe('extern')->and($g['kosten'])->toBe(0.28)
        ->and($g['zubehoer'])->toHaveCount(1)
        ->and($g['zubehoer'][0])->toMatchArray(['gp_id' => $this->deckel->id, 'menge' => 1.0, 'kostenart' => 'einweg', 'preis_stk' => 0.07, 'preis_quelle' => 'artikel', 'kosten' => 0.07])
        ->and($g['zubehoer_summe'])->toBe(0.07)->and($g['gesamt'])->toBe(0.35);

    // Menge 2 und eine zweite Darreichung mit demselben Glas: einmal am Glas gepflegt, überall gerechnet
    $d2 = ($this->gericht)('zweites');
    $this->svc->aktualisieren($this->rootTeam, $d2->id, ['vessel_gp_id' => $this->glas->id, 'unit_count' => 2]);
    expect(($this->ek)($d2))->toBe(4.7);   // 2 × (2,00 + 0,28 + 0,07)
    expect($this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, '2'))->toBe(2)
        ->and(($this->ek)())->toBe(2.42)->and(($this->ek)($d2))->toBe(4.84);

    // Deckel als Mehrweg mit eigenem Preis: seine Art gilt, nicht die des Glases
    $this->arten->setzen($this->rootTeam, $this->deckel->id, 'mehrweg', '0,02');
    $z = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam)['zubehoer'][0];
    expect($z['kostenart'])->toBe('mehrweg')->and($z['kosten'])->toBe(0.04)->and(($this->ek)())->toBe(2.32);

    $this->zub->entfernen($this->rootTeam, $this->glas->id, $this->deckel->id);
    expect(($this->ek)())->toBe(2.28)->and($this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam)['zubehoer'])->toBe([]);
});

it('Zubehör ohne Preis: ehrlich „kein Preis“, Darreichung unvollständig, nie still eingerechnet', function () {
    $this->arten->setzen($this->rootTeam, $this->deckel->id, 'mehrweg');   // Mehrweg ohne Preis am GP ⇒ Preis fehlt
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);

    $g = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam);
    expect($g['zubehoer'][0]['kosten'])->toBeNull()->and($g['zubehoer_preis_fehlt'])->toBeTrue()->and($g['preis_fehlt'])->toBeFalse()
        ->and($this->svc->beigabenKosten($this->dar->fresh(), $this->rootTeam)['vollstaendig'])->toBeFalse()
        ->and(($this->ek)())->toBe(2.28);

    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->html();
    expect($html)->toContain('data-dar-gefaess-zubehoer-preis-fehlt')->toContain('kein Preis')->toContain('(unvollständig)');
});

it('Kosten-Kaskade: Preis oder Art des Deckels ändern rechnet die Darreichungen mit dem Glas neu (auch über den Artikelpreis)', function () {
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);
    expect(($this->ek)())->toBe(2.35);

    // Art + Preis am Deckel-GP: die Darreichung mit dem GLAS rechnet neu, obwohl der Deckel nirgends direkt hängt
    expect($this->arten->setzen($this->rootTeam, $this->deckel->id, 'einweg', '0,10'))->toBe(1)->and(($this->ek)())->toBe(2.38);

    // Artikelpreis ändert sich (Lieferant): gezielter Anstoß über den Lieferantenartikel
    $this->arten->setzen($this->rootTeam, $this->deckel->id, null);
    FoodAlchemistPrice::where('supplier_item_id', $this->deckel->lead_la_supplier_item_id)->update(['price' => 1.20]);   // 0,10 €/Stk
    expect($this->svc->nachLaPreisAenderung((int) $this->deckel->lead_la_supplier_item_id))->toBe(1)->and(($this->ek)())->toBe(2.38);
});

it('Eine Ebene: kein Selbstbezug, kein Zubehör am Zubehör, kein Gefäß-mit-Zubehör als Zubehör; nur Non-Food, Menge > 0', function () {
    $etikett = ($this->nonFood)('Etikett: Papier, rund', 1.20);
    $butter = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'gz|butter', 'name' => 'Butter: frisch', 'status' => 'approved', 'commodity_group_code' => '06']);

    expect(fn () => $this->zub->setzen($this->rootTeam, $this->glas->id, $this->glas->id, 1))->toThrow(\RuntimeException::class, 'eigenes Zubehör')
        ->and(fn () => $this->zub->setzen($this->rootTeam, $this->glas->id, $butter->id, 1))->toThrow(\RuntimeException::class, 'Non-Food')
        ->and(fn () => $this->zub->setzen($this->rootTeam, $butter->id, $this->deckel->id, 1))->toThrow(\RuntimeException::class, 'Non-Food')
        ->and(fn () => $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 0))->toThrow(\RuntimeException::class, 'Menge')
        ->and(fn () => $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 'viel'))->toThrow(\RuntimeException::class, 'Menge');

    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);
    // Deckel bekäme eigenes Zubehör → Deckel ist schon Zubehör: abgewiesen (kein Zyklus Glas → Deckel → …)
    expect(fn () => $this->zub->setzen($this->rootTeam, $this->deckel->id, $etikett->id, 1))->toThrow(\RuntimeException::class, 'selbst Zubehör')
        // Glas hat Zubehör → Glas kann nicht Zubehör eines anderen Gefäßes werden
        ->and(fn () => $this->zub->setzen($this->rootTeam, $etikett->id, $this->glas->id, 1))->toThrow(\RuntimeException::class, 'hat selbst Zubehör');
    expect($this->zub->liste($this->deckel->id, $this->rootTeam)['zeilen'])->toBe([]);
});

it('Je Team: Unter-Team erbt die Liste, Änderung übernimmt sie ins eigene Team, „alles entfernt“ bleibt leer, Zurücksetzen erbt wieder', function () {
    $etikett = ($this->nonFood)('Etikett: Papier, rund', 1.20);   // 0,10 €/Stk
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);
    expect($this->zub->liste($this->glas->id, $this->childA))->toMatchArray(['team_id' => $this->rootTeam->id])
        ->and(array_column($this->zub->liste($this->glas->id, $this->childA)['zeilen'], 'gp_id'))->toBe([$this->deckel->id]);

    // Standort A ergänzt ein Etikett: Deckel wird mit übernommen, das Hauptteam bleibt unverändert
    $this->zub->setzen($this->childA, $this->glas->id, $etikett->id, 1);
    expect(array_column($this->zub->liste($this->glas->id, $this->childA)['zeilen'], 'gp_id'))->toBe([$this->deckel->id, $etikett->id])
        ->and($this->zub->liste($this->glas->id, $this->childA)['team_id'])->toBe($this->childA->id)
        ->and(array_column($this->zub->liste($this->glas->id, $this->rootTeam)['zeilen'], 'gp_id'))->toBe([$this->deckel->id])
        ->and(array_column($this->zub->liste($this->glas->id, $this->childB)['zeilen'], 'gp_id'))->toBe([$this->deckel->id]);

    // A entfernt alles: eigene leere Liste, erbt den Deckel NICHT wieder
    $this->zub->entfernen($this->childA, $this->glas->id, $this->deckel->id);
    $this->zub->entfernen($this->childA, $this->glas->id, $etikett->id);
    expect($this->zub->liste($this->glas->id, $this->childA))->toBe(['team_id' => $this->childA->id, 'zeilen' => []]);

    $this->zub->zuruecksetzen($this->childA, $this->glas->id);
    expect($this->zub->liste($this->glas->id, $this->childA)['team_id'])->toBe($this->rootTeam->id);
});

it('Kein Doppelzählen: steht der Deckel zusätzlich als Zubehör an der Darreichung, zählt er nur am Gefäß', function () {
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);
    $this->svc->setzeZubehoer($this->rootTeam, $this->dar->id, $this->deckel->id, 1);

    $zeile = $this->svc->zubehoerKosten($this->dar->fresh(), $this->rootTeam)['zeilen'][0];
    expect(($this->ek)())->toBe(2.35)->and($zeile['doppelt'])->toBeTrue()->and($zeile['kosten'])->toBeNull()
        ->and($this->svc->beigabenKosten($this->dar->fresh(), $this->rootTeam)['vollstaendig'])->toBeTrue();
});

it('Gericht-Modal: Preis direkt unter dem Gefäß, Zubehör mit Menge und Preis, Summe je Portion; KPI-Zeile nur bei Nicht-Standard', function () {
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);

    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->html();
    expect($html)->toContain('data-dar-gefaess-preis')->toContain('data-dar-gefaess-kosten')->toContain('Gefäß 0,28 € je Einheit (Preis am Grundprodukt)')
        ->toContain('data-dar-gefaess-zubehoer="'.$this->deckel->id.'"')->toContain('1 × Deckel: Twist-off, 82 mm')->toContain('0,07 €')
        ->toContain('Gefäß + Zubehör je Portion 0,35 €')
        ->not->toContain('data-dar-kennzahlen')->not->toContain('VK brutto <');   // Standard: Kennzahlen stehen oben im Kopf

    // weitere Darreichung (nicht Standard): kompakte Kennzahlen-Zeile
    $sf = FoodAlchemistServierform::create(['code' => 'flying', 'team_id' => $this->rootTeam->id, 'label' => 'Flying']);
    // Plan 98 B: Gericht ohne Komponenten (EK je kg als Fixture) — kein Anteil berechenbar, deshalb Altbestand-Fixture
    $weitere = $this->grammaturAltbestand($this->svc->anlegen($this->rootTeam, $this->dar->recipe_id, $sf->id), 50);
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->set('darAktiv', $weitere->id)->html();
    expect($html)->toContain('data-dar-kennzahlen')->toContain('Diese Darreichung: EK');
});

it('Beschriftung „Extern (Miete / Spülservice)“, Hinweis „Preis je Einsatz“ — der Schlüssel bleibt extern', function () {
    expect(GefaessKostenartService::ARTEN[GefaessKostenartService::EXTERN])->toBe('Extern (Miete / Spülservice)')
        ->and(GefaessKostenartService::pruefe('extern'))->toBe('extern');
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'extern');   // ohne Preis am GP ⇒ Artikelpreis
    $this->svc->recomputePreise($this->dar->fresh());

    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->html();
    expect($html)->toContain('Gefäß 0,50 € je Einheit (Preis je Einsatz (Miete/Spülservice))')->toContain('Extern (Miete / Spülservice)')
        ->not->toContain('Mietpreis des Artikels');
    expect(DB::table('foodalchemist_gp_gefaess_kostenarten')->where('gp_id', $this->glas->id)->value('kostenart'))->toBe('extern');
});

it('GP-Detail: Zubehör suchen, hinzufügen, Menge ändern, entfernen; Fehler sichtbar', function () {
    $p = Livewire::test(DetailPanel::class, ['gpId' => $this->glas->id, 'embedded' => true, 'section' => 'lager']);
    expect($p->html())->toContain('data-gp-gefaess-zubehoer')->toContain('Kein Zubehör');

    $p->set('gefaessZubehoerSuche', 'Deckel');
    expect($p->html())->toContain('data-gp-gefaess-zubehoer-treffer')->toContain('Deckel: Twist-off, 82 mm');
    $p->call('gefaessZubehoerHinzu', $this->deckel->id);
    expect(array_column($this->zub->liste($this->glas->id, $this->rootTeam)['zeilen'], 'gp_id'))->toBe([$this->deckel->id])
        ->and(($this->ek)())->toBe(2.35)->and($p->get('gefaessZubehoerSuche'))->toBe('')
        ->and($p->html())->toContain('data-gp-gefaess-zubehoer-zeile="'.$this->deckel->id.'"')->toContain('Zubehör je Gefäß 0,07 €');

    $p->call('gefaessZubehoerMengeSetzen', $this->deckel->id, '2');
    expect(($this->ek)())->toBe(2.42);
    $p->call('gefaessZubehoerHinzu', $this->glas->id);
    expect($p->get('gefaessZubehoerFehler'))->toContain('eigenes Zubehör')->and($p->html())->toContain('data-gp-gefaess-zubehoer-fehler');
    $p->call('gefaessZubehoerWeg', $this->deckel->id);
    expect(($this->ek)())->toBe(2.28);
});

it('MCP: gp_gefaess_zubehoer.PUT setzt, liefert Liste mit Preisen, entfernt; Darreichungs-GET zeigt Zubehör + Kosten', function () {
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $tool = new GpGefaessZubehoerPutTool;

    $r = $tool->execute(['gefaess_gp_id' => $this->glas->id, 'setzen' => [['gp_id' => $this->deckel->id, 'menge' => '1']]], $ctx);
    expect($r->success)->toBeTrue((string) ($r->error ?? ''))->and($r->data['darreichungen_neu_gerechnet'])->toBe(1)
        ->and($r->data['zubehoer'][0])->toMatchArray(['gp_id' => $this->deckel->id, 'kostenart' => 'einweg', 'kosten_je_gefaess' => 0.07, 'preis_fehlt' => false])
        ->and($r->data['zubehoer_summe_je_gefaess'])->toBe(0.07)->and(($this->ek)())->toBe(2.35);

    $summary = (new ReflectionMethod($tool, 'darreichungenSummary'))->invoke($tool, $this->dar->recipe()->first());
    expect($summary[0]['gefaess']['zubehoer'][0])->toMatchArray(['gp_id' => $this->deckel->id, 'kosten_je_einheit' => 0.07, 'kostenart' => 'einweg'])
        ->and($summary[0]['gefaess']['kosten_gesamt_je_einheit'])->toBe(0.35)->and($summary[0]['gefaess']['zubehoer_preis_fehlt'])->toBeFalse();

    expect($tool->execute(['gefaess_gp_id' => $this->glas->id, 'setzen' => [['gp_id' => $this->glas->id]]], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['gefaess_gp_id' => $this->glas->id, 'setzen' => [['menge' => 1]]], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute([], $ctx)->errorCode)->toBe('VALIDATION_ERROR');

    $r = $tool->execute(['gefaess_gp_id' => $this->glas->id, 'entfernen' => [$this->deckel->id]], $ctx);
    expect($r->success)->toBeTrue()->and($r->data['zubehoer'])->toBe([])->and(($this->ek)())->toBe(2.28);
});

it('Planungsblatt + Produktionsschein: Gefäß und Zubehör auf die Portionen hochgerechnet („Mitgeben“), Menge je Gefäß zählt', function () {
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);
    $blatt = app(\Platform\FoodAlchemist\Services\PlanungsblattService::class)->produktionsblatt($this->rootTeam, ['recipe_id' => $this->dar->recipe_id, 'portions' => 120]);
    $top = collect($blatt['rezepte'])->firstWhere('recipe_id', $this->dar->recipe_id);
    expect(data_get($top, 'darreichung.mitgeben'))->toBe('120 × Achenbachglas: Glas, 212 ml · 120 × Deckel: Twist-off, 82 mm');

    $html = view('foodalchemist::dokumente.blatt', ['blatt' => $blatt, 'typ' => 'produktion', 'titel' => 'Produktionsblatt', 'untertitel' => 'x', 'mitFotos' => false, 'istPdf' => true])->render();
    expect($html)->toContain('Mitgeben: 120 × Achenbachglas: Glas, 212 ml · 120 × Deckel: Twist-off, 82 mm');

    // 2 Deckel je Glas, 45 Portionen: 45 Gläser, 90 Deckel
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 2);
    $orders = app(\Platform\FoodAlchemist\Services\Produktion\ProductionOrderService::class);
    $order = $orders->draftForDate($this->rootTeam, '2026-12-01');
    $orders->addTarget($this->rootTeam, $order->id, ['recipe_id' => $this->dar->recipe_id, 'portions' => 45], 'a');
    $dok = $orders->dokument($this->rootTeam, $order->id);
    $zeile = collect($dok['zeilen'])->firstWhere('name', 'Dessert dessert');
    expect(data_get($zeile, 'darreichung.mitgeben'))->toBe('45 × Achenbachglas: Glas, 212 ml · 90 × Deckel: Twist-off, 82 mm');
    $html = view('foodalchemist::dokumente.produktionsauftrag', ['dok' => $dok, 'istPdf' => true, 'optionen' => $orders->dokumentOptionen([]), 'mitFotos' => false])->render();
    expect($html)->toContain('Mitgeben: 45 × Achenbachglas: Glas, 212 ml · 90 × Deckel: Twist-off, 82 mm');
});

it('Handbuch: Reiter „Gefäß“ statt „Bestandteile“ — Gefäß + Zubehör je Portion, ohne Preise; ohne Gefäß ehrlich leer', function () {
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);
    $handbuch = app(\Platform\FoodAlchemist\Services\Ausgabe\HandbuchService::class);
    $karte = $handbuch->gerichtKarte($this->rootTeam, FoodAlchemistRecipe::find($this->dar->recipe_id), 'Dessert', false, $this->dar->fresh());
    expect($karte['gefaess'])->toBe(['1 × Achenbachglas: Glas, 212 ml', '1 × Deckel: Twist-off, 82 mm'])
        ->and($karte)->not->toHaveKey('bestandteile')
        ->and(json_encode($karte, JSON_UNESCAPED_UNICODE))->not->toContain('€')->not->toContain('0,28')->not->toContain('0.28');

    $ohne = ($this->gericht)('ohne-glas');
    expect($handbuch->gerichtKarte($this->rootTeam, FoodAlchemistRecipe::find($ohne->recipe_id), 'Ohne', false, $ohne)['gefaess'])->toBe([]);

    $snapshot = require dirname(__DIR__) . '/Fixtures/handbuch_ansicht.php';
    $snapshot['gerichte']['r1']['gefaess'] = $karte['gefaess'];
    unset($snapshot['gerichte']['r2']['gefaess']);   // alte Fassung ohne das Feld: kein Reiter
    $html = view('foodalchemist::handbuch.show', compact('snapshot'))->render();
    expect($html)->toContain('data-pane="gefaess">Gefäß</button>')->toContain('1 × Deckel: Twist-off, 82 mm')
        ->not->toContain('data-pane="bestandteile"')->not->toContain('Bestandteile')
        ->and(substr_count($html, 'data-pane="gefaess">Gefäß</button>'))->toBe(1);
});

it('Fassungs-Hash: Zubehör am Gefäß ist neuer Inhalt; ohne Zubehör bleibt der Hash wie bisher', function () {
    $fassungen = app(\Platform\FoodAlchemist\Services\FassungService::class);
    $hash = fn () => (new ReflectionMethod($fassungen, 'inhaltHash'))->invoke($fassungen, FoodAlchemistRecipe::find($this->dar->recipe_id), []);
    $vorher = $hash();
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 1);
    $mit = $hash();
    expect($mit)->not->toBe($vorher);
    $this->zub->setzen($this->rootTeam, $this->glas->id, $this->deckel->id, 2);
    expect($hash())->not->toBe($mit);
    $this->zub->entfernen($this->rootTeam, $this->glas->id, $this->deckel->id);
    expect($hash())->toBe($vorher);
});
