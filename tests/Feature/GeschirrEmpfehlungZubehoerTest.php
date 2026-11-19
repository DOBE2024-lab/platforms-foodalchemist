<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Support\ZubehoerAuswahl;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 19.11.2026 (Dominique): an der Darreichung zwei Felder nebeneinander — Gefäß (Ware mit Lieferant und Preis, geht in den EK) und
 * Geschirr-Empfehlung (Servier-Typ aus den Einstellungen, kostet nichts). Dazu „Zubehör & Verpackung“: Klick ins Feld zeigt eine Liste,
 * nur Einweg & Verpackung, Deko, Geschirr — nie Reinigung, Arbeitskleidung, Büro.
 */
function geschirrNonFoodGp(\Platform\Core\Models\Team $team, string $name, string $sub): FoodAlchemistGp
{
    return FoodAlchemistGp::create(['team_id' => $team->id, 'gp_key' => 'nf|'.mb_strtolower($name), 'name' => $name, 'status' => 'approved',
        'commodity_group_code' => NonFoodRegeln::WG_CODE, 'sub_category' => $sub]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Küche'));
    FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);
    $this->bowl = DB::table('foodalchemist_vocab_serving_vehicles')->insertGetId(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'slug' => 'bowl', 'name' => 'Bowl', 'sort_order' => 1, 'is_inactive' => false, 'created_at' => now(), 'updated_at' => now()]);
    $g = FoodAlchemistRecipe::create(['team_id' => $this->rootTeam->id, 'recipe_key' => 'curry', 'name' => 'Curry', 'status' => 'approved',
        'is_sales_recipe' => true, 'ek_per_kg_eur' => 10.0, 'yield_kg' => 1.0]);
    $this->dar = app(DarreichungService::class)->ensureStandard($this->rootTeam, $g->id);

    $this->becher = geschirrNonFoodGp($this->rootTeam, 'Dressingbecher: PP, 50 ml', NonFoodRegeln::SUB_EINWEG);
    $this->serviette = geschirrNonFoodGp($this->rootTeam, 'Serviette: weiß, 33 x 33 cm', NonFoodRegeln::SUB_DEKO);
    $this->schale = geschirrNonFoodGp($this->rootTeam, 'Schale: Porzellan, 12 cm', NonFoodRegeln::SUB_GESCHIRR);
    $this->reiniger = geschirrNonFoodGp($this->rootTeam, 'Desinfektionsreiniger: 5 l', NonFoodRegeln::SUB_REINIGUNG);
    $this->kittel = geschirrNonFoodGp($this->rootTeam, 'Besucherkittel: PP, weiß', NonFoodRegeln::SUB_KLEIDUNG);
});

it('★ Geschirr-Empfehlung steht als eigenes Feld neben dem Gefäß und lässt sich ohne Gefäß setzen — kein EK', function () {
    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id);
    $html = $c->html();
    expect($html)->toContain('Geschirr-Empfehlung')->toContain('data-dar-typ')
        ->and(strpos($html, 'data-dar-gefaess'))->toBeLessThan(strpos($html, 'data-dar-typ'));   // Empfehlung folgt direkt auf das Gefäß

    $ekVorher = (float) $this->dar->fresh()->ek_portion;
    $c->set('darForm.'.$this->dar->id.'.serving_vehicle_vocab_id', (string) $this->bowl)->call('darreichungSpeichern', $this->dar->id)->assertSet('fehler', null);
    expect((int) $this->dar->fresh()->serving_vehicle_vocab_id)->toBe($this->bowl)
        ->and($this->dar->fresh()->vessel_gp_id)->toBeNull()
        ->and((float) $this->dar->fresh()->ek_portion)->toBe($ekVorher)
        ->and(app(DarreichungService::class)->vehikelId($this->dar->fresh()))->toBe($this->bowl);
});

it('ohne Servier-Typen im Team zeigt das Feld, wo die Liste gepflegt wird', function () {
    DB::table('foodalchemist_vocab_serving_vehicles')->delete();
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id)->html();
    expect($html)->toContain('data-dar-typ-leer')->toContain('Servier-Vehikel');
});

it('★ Zubehör: ohne Suchtext eine Liste nur aus Einweg, Deko und Geschirr, gruppiert; Reinigung und Kleidung nie', function () {
    $liste = ZubehoerAuswahl::treffer(FoodAlchemistGp::visibleToTeam($this->rootTeam), '');
    expect($liste->pluck('id')->all())->toBe([$this->becher->id, $this->serviette->id, $this->schale->id])   // Reihenfolge = Gruppen-Reihenfolge
        ->and($liste->pluck('gruppe')->unique()->values()->all())->toBe(ZubehoerAuswahl::UNTERKATEGORIEN)
        ->and(ZubehoerAuswahl::treffer(FoodAlchemistGp::visibleToTeam($this->rootTeam), 'Desinfektion'))->toHaveCount(0)
        ->and(ZubehoerAuswahl::treffer(FoodAlchemistGp::visibleToTeam($this->rootTeam), 'becher')->pluck('id')->all())->toBe([$this->becher->id])
        ->and(ZubehoerAuswahl::gruppenName(NonFoodRegeln::SUB_EINWEG))->toBe('Einweg & Verpackung');

    $neu = FoodAlchemistGp::create(['team_id' => $this->rootTeam->id, 'gp_key' => 'nf|neu', 'name' => 'Holzspieß: 15 cm', 'status' => 'approved', 'commodity_group_code' => NonFoodRegeln::WG_CODE]);
    $liste = ZubehoerAuswahl::treffer(FoodAlchemistGp::visibleToTeam($this->rootTeam), '');
    expect($liste->last()->id)->toBe($neu->id)->and($liste->last()->gruppe)->toBe(ZubehoerAuswahl::OHNE);   // Neues ohne Unterkategorie verschwindet nicht
});

it('Zubehör im Modal: zu bis zum Klick, Klick öffnet die Liste, Auswahl hängt an und schließt', function () {
    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->dar->recipe_id);
    expect($c->html())->not->toContain('data-zubehoer-treffer');

    $c->set('darZubehoerOffen', $this->dar->id);
    $html = $c->html();
    expect($html)->toContain('data-zubehoer-treffer')->toContain('data-zubehoer-gruppe')->toContain('Dressingbecher')
        ->not->toContain('Desinfektionsreiniger')->not->toContain('Besucherkittel');

    $c->call('darZubehoerHinzu', $this->dar->id, $this->becher->id)->assertSet('fehler', null)->assertSet('darZubehoerOffen', null);
    expect(DB::table('foodalchemist_recipe_presentation_zubehoer')->where('presentation_id', $this->dar->id)->where('gp_id', $this->becher->id)->exists())->toBeTrue();
});
