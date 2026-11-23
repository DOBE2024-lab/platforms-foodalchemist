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
use Platform\FoodAlchemist\Services\GpMasseService;
use Platform\FoodAlchemist\Services\PlanungsblattService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpGefaessKostenartPutTool;
use Platform\FoodAlchemist\Tools\RecipeDarreichungPutTool;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Art + Preis des Gefäßes (Entscheid Dominique 17.11.2026, Pilot TASTE & FLY): einweg | mehrweg | extern, Preis je Stück je Team am GP
 * (Standorte erben vom Hauptteam, eigener Eintrag gewinnt), Art je Darreichung übersteuerbar; ohne Eintrag das bisherige Verhalten.
 * einweg/extern: Preis am GP, sonst Artikelpreis (extern seit 18.11.2026) · mehrweg: Preis am GP · fehlt der Preis: Hinweis + unvollständig.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->svc = app(DarreichungService::class);
    $this->arten = app(GefaessKostenartService::class);
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);

    $fahrzeug = fn (string $name) => DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'slug' => mb_strtolower($name), 'name' => $name, 'sort_order' => 1, 'is_inactive' => false, 'created_at' => now(), 'updated_at' => now()]);
    $this->glasTyp = $fahrzeug('Glas');
    $this->tellerTyp = $fahrzeug('Teller');

    $this->gefaess = function (string $name, ?float $gebindePreis, ?int $typ) {
        $gp = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'gk|'.mb_strtolower($name), 'name' => $name, 'status' => 'approved',
            'commodity_group_code' => '16', 'sub_category' => NonFoodRegeln::SUB_GESCHIRR]);
        $sup = FoodAlchemistSupplier::firstOrCreate(['team_id' => $this->rootTeam->id, 'name' => 'Verpackungs-Großhandel']);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => $name, 'qty' => 12.0, 'unit_code' => 'Stk']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        if ($gebindePreis !== null) {
            FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $la->id, 'price' => $gebindePreis, 'status' => '0']);
        }
        $gp->update(['lead_la_supplier_item_id' => $la->id]);
        if ($typ !== null) {
            app(GpMasseService::class)->setzeServierTyp($gp->refresh(), $typ);
        }

        return $gp->refresh();
    };

    // Gericht: 10 €/kg, 200 g je Einheit ⇒ Lebensmittel-EK 2,00 €
    $this->gericht = function (string $key = 'dessert') {
        $g = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => 'Dessert '.$key, 'status' => 'approved', 'is_sales_recipe' => true, 'ek_per_kg_eur' => 10.0, 'yield_kg' => 1.0]);
        $d = $this->svc->ensureStandard($this->rootTeam, $g->id);
        // Explizite Bestandsgrammatur — Plan 98 B: Grammatur als Altbestand-Fixture (freie Grammatur ist über den Service gesperrt)
        $d = $this->grammaturAltbestand($d, 200, 1);

        return $d->fresh();
    };
    $this->dar = ($this->gericht)();
    $this->ek = fn ($d = null) => (float) ($d ?? $this->dar)->fresh()->ek_portion;
    $this->glas = ($this->gefaess)('Schraubglas: Glas, 212 ml', 6.00, $this->glasTyp);   // 0,50 €/Stk
});

it('Gegenprobe ohne Eintrag: Einweg-Gefäß kostet wie bisher den Artikelpreis, Art einweg aus der Automatik', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);

    $g = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.5)
        ->and($g['kostenart'])->toBe('einweg')->and($g['kostenart_quelle'])->toBe('automatik')->and($g['kosten'])->toBe(0.5)
        ->and($g['preis_quelle'])->toBe('artikel')->and($g['preis_fehlt'])->toBeFalse()
        ->and($this->svc->beigabenKosten($this->dar->fresh(), $this->rootTeam)['vollstaendig'])->toBeTrue();
});

it('Einweg mit Preis am GP: der Preis des Teams geht vor den Artikelpreis', function () {
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'einweg', '0,30');
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $g = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.3)->and($g['preis_quelle'])->toBe('gp')->and($g['kostenart_quelle'])->toBe('team');
});

it('Mehrweg: kein Einkaufspreis, sondern der Preis am GP (z. B. Spülkosten) im Wareneinsatz; Anzahl zählt mit', function () {
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg', 0.08);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $g = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.08)->and($g['kostenart'])->toBe('mehrweg')->and($g['kosten'])->toBe(0.08)->and($g['preis_fehlt'])->toBeFalse();

    $teller = ($this->gefaess)('Speiseteller: Porzellan, Ø 27 cm', 24.0, $this->tellerTyp);   // Hauptgang: Teller einfach Mehrweg mit Preis
    $this->arten->setzen($this->rootTeam, $teller->id, 'mehrweg', 0.12);
    $d2 = ($this->gericht)('haupt');
    $this->svc->aktualisieren($this->rootTeam, $d2->id, ['vessel_gp_id' => $teller->id, 'unit_count' => 2]);
    expect(($this->ek)($d2))->toBe(4.24);   // 2 × 2,00 + 2 × 0,12, nicht der Artikelpreis 2,00 je Teller
});

it('Mehrweg ohne Preis am GP: „Gefäß-Preis fehlt“, Kosten 0 im EK, unvollständig — extern ohne Preis am GP nimmt den Artikelpreis', function () {
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg');
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    $info = $this->svc->gefaessInfo($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.0)->and($info['preis_fehlt'])->toBeTrue()->and($info['kosten'])->toBeNull()
        ->and($this->svc->beigabenKosten($this->dar->fresh(), $this->rootTeam)['vollstaendig'])->toBeFalse();

    // extern ohne Preis am GP: Lead-Artikelpreis je Stück wie einweg (Entscheid 17.11.2026, Befund Achenbachglas 18.11.)
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'extern');
    $info = $this->svc->gefaessInfo($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.5)->and($info['preis_quelle'])->toBe('artikel')->and($info['preis_fehlt'])->toBeFalse()->and($info['miete'])->toBeTrue();

    $this->arten->setzen($this->rootTeam, $this->glas->id, 'extern', '0,55');
    expect(($this->ek)())->toBe(2.55)->and($this->svc->gefaessInfo($this->dar->fresh(), $this->rootTeam)['miete'])->toBeTrue();
});

// Bewusst umgestellt 18.11.2026 (vorher: „Verleih ohne Eintrag = Miet-Hinweis ohne Kosten, vollständig“): extern rechnet mit dem Preis —
// Team-Preis am GP, sonst Lead-Artikelpreis je Stück; fehlt beides, „Gefäß-Preis fehlt“ und unvollständig (nie mehr still „nicht je Portion“).
it('Verleih-Automatik extern: ohne Eintrag zählt der Artikelpreis je Stück, der Preis am GP geht vor, ohne jeden Preis „Gefäß-Preis fehlt“', function () {
    app(\Platform\FoodAlchemist\Services\SupplierGroupService::class)->einrichten(true);
    $verleih = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierGroup::whereNull('team_id')->where('code', 'VERLEIH')->firstOrFail();
    $miet = ($this->gefaess)('Speiseteller Miete', 0.55, $this->tellerTyp);
    $verleiher = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Eventausstatter', 'group_id' => $verleih->id]);
    FoodAlchemistSupplierItem::whereKey($miet->lead_la_supplier_item_id)->update(['supplier_id' => $verleiher->id]);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $miet->id]);

    $info = $this->svc->gefaessInfo($this->dar->fresh(), $this->rootTeam);
    // Artikel: 0,55 € je Gebinde à 12 Stk ⇒ 0,0458 € je Stück
    expect(($this->ek)())->toBe(2.0458)->and($info['kostenart'])->toBe('extern')->and($info['kostenart_quelle'])->toBe('automatik')
        ->and($info['miete'])->toBeTrue()->and($info['kosten'])->toBe(0.0458)->and($info['preis_quelle'])->toBe('artikel')->and($info['preis_fehlt'])->toBeFalse()
        ->and($this->svc->beigabenKosten($this->dar->fresh(), $this->rootTeam)['vollstaendig'])->toBeTrue();

    $this->arten->setzen($this->rootTeam, $miet->id, 'extern', 0.4);   // Team-Preis am GP geht vor
    expect(($this->ek)())->toBe(2.4)->and($this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam)['preis_quelle'])->toBe('gp');

    // ohne jeden Preis (kein Team-Preis, Artikel unbepreist): Hinweis + unvollständig, kein stiller Miet-Hinweis
    $this->arten->setzen($this->rootTeam, $miet->id, null);
    \Platform\FoodAlchemist\Models\FoodAlchemistPrice::where('supplier_item_id', $miet->lead_la_supplier_item_id)->delete();
    $this->svc->recomputePreise($this->dar->fresh());
    $info = $this->svc->gefaessInfo($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.0)->and($info['kostenart_quelle'])->toBe('automatik')->and($info['kosten'])->toBeNull()->and($info['preis_fehlt'])->toBeTrue()
        ->and($this->svc->beigabenKosten($this->dar->fresh(), $this->rootTeam)['vollstaendig'])->toBeFalse();
    $html = \Livewire\Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->html();
    expect($html)->toContain('data-dar-gefaess-preis-fehlt')->not->toContain('nicht je Portion gerechnet');
});

it('Übersteuerung der Art je Darreichung schlägt den Team-Eintrag; leer kehrt zurück; unbekannter Wert wird abgewiesen', function () {
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg', 0.1);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    expect(($this->ek)())->toBe(2.1);

    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_cost_type' => 'einweg']);   // Preis am GP gilt auch für Einweg
    $g = $this->svc->gefaessKosten($this->dar->fresh(), $this->rootTeam);
    expect(($this->ek)())->toBe(2.1)->and($g['kostenart_quelle'])->toBe('darreichung')->and($g['kostenart_vorgabe'])->toBe('mehrweg');

    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_cost_type' => '']);
    expect($this->dar->fresh()->vessel_cost_type)->toBeNull();
    expect(fn () => $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_cost_type' => 'empfehlung']))->toThrow(\RuntimeException::class, 'Art');
});

it('Je Team am GP: Standort erbt Art + Preis des Hauptteams, eigener Eintrag gewinnt; Entfernen fällt zurück; nur Non-Food, Preis ≥ 0', function () {
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg', 0.08);
    expect($this->arten->teamVorgabe($this->glas->id, $this->childA))->toBe(['kostenart' => 'mehrweg', 'preis' => 0.08, 'team_id' => $this->rootTeam->id]);

    $this->arten->setzen($this->childA, $this->glas->id, 'einweg');
    expect($this->arten->teamVorgabe($this->glas->id, $this->childA)['kostenart'])->toBe('einweg')
        ->and($this->arten->teamVorgabe($this->glas->id, $this->childB)['kostenart'])->toBe('mehrweg');

    $this->arten->setzen($this->childA, $this->glas->id, null);
    expect($this->arten->teamVorgabe($this->glas->id, $this->childA)['kostenart'])->toBe('mehrweg');
    $this->arten->setzen($this->rootTeam, $this->glas->id, '');
    expect($this->arten->teamVorgabe($this->glas->id, $this->childA)['kostenart'])->toBeNull();
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'extern', 0.5);   // neu setzen nach Entfernen (gelöschte Zeile wird wiederhergestellt)
    expect($this->arten->teamVorgabe($this->glas->id, $this->rootTeam))->toMatchArray(['kostenart' => 'extern', 'preis' => 0.5]);

    $butter = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'gk|butter', 'name' => 'Butter: frisch', 'status' => 'approved', 'commodity_group_code' => '06']);
    expect(fn () => $this->arten->setzen($this->rootTeam, $butter->id, 'mehrweg'))->toThrow(\RuntimeException::class, 'Non-Food')
        ->and(fn () => $this->arten->setzen($this->rootTeam, $this->glas->id, 'quatsch'))->toThrow(\RuntimeException::class, 'Art')
        ->and(fn () => $this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg', '-1'))->toThrow(\RuntimeException::class, 'Preis');
});

it('Team-Eintrag ändern rechnet die Darreichungen mit diesem Gefäß neu', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);
    expect(($this->ek)())->toBe(2.5);
    expect($this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg', 0.2))->toBeGreaterThanOrEqual(1)->and(($this->ek)())->toBe(2.2);
});

it('Planungsblatt: Geschirr trägt die Art für die Logistik (Mehrweg zurück in die Spülküche)', function () {
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg', 0.08);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);

    $blatt = app(PlanungsblattService::class)->produktionsblatt($this->rootTeam, ['recipe_id' => $this->dar->recipe_id, 'persons' => 10]);
    $top = collect($blatt['rezepte'])->firstWhere('recipe_id', $this->dar->recipe_id);
    expect(data_get($top, 'darreichung.geschirr'))->toBe('Schraubglas: Glas, 212 ml')
        ->and(data_get($top, 'darreichung.geschirr_art'))->toBe(GefaessKostenartService::LOGISTIK['mehrweg']);
});

it('Fassungs-Hash: ohne gesetzte Art unverändert, eine Übersteuerung ist neuer Inhalt', function () {
    $fassungen = app(\Platform\FoodAlchemist\Services\FassungService::class);
    $hash = fn () => (new ReflectionMethod($fassungen, 'inhaltHash'))->invoke($fassungen, $this->dar->recipe()->first(), []);
    $vorher = $hash();
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_cost_type' => '']);
    expect($hash())->toBe($vorher);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_cost_type' => 'mehrweg']);
    expect($hash())->not->toBe($vorher);
});

it('MCP: gp_gefaess_kostenart.PUT und recipe_darreichung.PUT vessel_cost_type — ausführend', function () {
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);

    $r = (new GpGefaessKostenartPutTool)->execute(['gp_ids' => [$this->glas->id], 'kostenart' => 'mehrweg', 'preis_stk' => '0,3'], $ctx);
    expect($r->success)->toBeTrue((string) ($r->error ?? ''))->and($r->data['kostenart'])->toBe('mehrweg')->and($r->data['preis_stk'])->toBe(0.3)->and(($this->ek)())->toBe(2.3)
        ->and((new GpGefaessKostenartPutTool)->execute(['gp_ids' => [$this->glas->id], 'kostenart' => 'pfand'], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and((new GpGefaessKostenartPutTool)->execute(['gp_ids' => [$this->glas->id], 'kostenart' => 'mehrweg', 'preis_stk' => 'teuer'], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and((new GpGefaessKostenartPutTool)->execute(['gp_ids' => []], $ctx)->errorCode)->toBe('VALIDATION_ERROR');

    $p = (new RecipeDarreichungPutTool)->execute(['presentation_id' => $this->dar->id, 'attrs' => ['vessel_cost_type' => 'extern']], $ctx);
    expect($p->success)->toBeTrue((string) ($p->error ?? ''))->and($this->dar->fresh()->vessel_cost_type)->toBe('extern')->and(($this->ek)())->toBe(2.3)
        ->and((new RecipeDarreichungPutTool)->execute(['presentation_id' => $this->dar->id, 'attrs' => ['vessel_cost_type' => 'pfand']], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and((new RecipeDarreichungPutTool)->getDescription())->toContain('vessel_cost_type');

    $r = (new GpGefaessKostenartPutTool)->execute(['gp_ids' => [$this->glas->id], 'kostenart' => null], $ctx);
    expect($r->success)->toBeTrue()->and($this->arten->teamVorgabe($this->glas->id, $this->rootTeam)['kostenart'])->toBeNull();
});

it('Oberfläche: Gericht-Modal zeigt Art + „Gefäß-Preis fehlt“ und speichert die Übersteuerung; GP-Lager setzt Art + Preis des Teams', function () {
    $this->arten->setzen($this->rootTeam, $this->glas->id, 'mehrweg');
    $this->svc->aktualisieren($this->rootTeam, $this->dar->id, ['vessel_gp_id' => $this->glas->id]);

    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id);
    expect($c->html())->toContain('data-dar-kostenart')->toContain('data-dar-gefaess-preis-fehlt')->toContain('Vorgabe: Mehrweg');
    $c->set('darForm.'.$this->dar->id.'.vessel_cost_type', 'einweg')->call('darreichungSpeichern', $this->dar->id);
    expect($this->dar->fresh()->vessel_cost_type)->toBe('einweg')->and(($this->ek)())->toBe(2.5);

    $teller = ($this->gefaess)('Speiseteller: Porzellan, Ø 27 cm', 24.0, $this->tellerTyp);
    $p = Livewire::test(DetailPanel::class, ['gpId' => $teller->id, 'embedded' => true, 'section' => 'lager']);
    expect($p->html())->toContain('data-gp-gefaess-kostenart')->toContain('data-gp-gefaess-preis');
    $p->set('gefaessKostenart', 'mehrweg')->set('gefaessPreis', '0,12')->call('gefaessKostenartSetzen');
    expect($this->arten->teamVorgabe($teller->id, $this->rootTeam))->toMatchArray(['kostenart' => 'mehrweg', 'preis' => 0.12]);
});
