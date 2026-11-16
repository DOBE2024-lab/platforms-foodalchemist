<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\FoodAlchemist\Services\SteuerklasseService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Steuerklasse statt freier MwSt-Zahl (Entscheid Dominique 16.11.2026): Klasse am GP/Artikel, Satz aus den Einstellungen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(SteuerklasseService::class);
    $this->gp = fn (string $name, string $wg, string $main = '') => (object) ['name' => $name, 'main_ingredient_display' => $main ?: strtok($name, ':'), 'commodity_group_code' => $wg, 'sub_category' => null, 'vat_profile_key' => null];
});

it('Regel: Lebensmittel ermäßigt; Getränke, Alkohol, Zusatzstoffe, Non-Food regulär; Kaffee/Tee/Milch ermäßigt', function () {
    expect($this->svc->regelFuerGp(($this->gp)('Dinkel: trocken', '07')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Basilikum: gerebelt, trocken', '10')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Kokoswasser: 1 l / (King Island)', '15')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Pils: 4,9 vol-%, Glasflasche 0,5 l', '15')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Kaffee: löslich, trocken / (Nescafé, Gold)', '15')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Jasmintee: lose, trocken', '15')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Tomatensaft: Glasflasche 0,2 l', '15')))->toBe('regulaer')   // „mate“ in Tomate zählt nicht
        ->and($this->svc->regelFuerGp(($this->gp)('Cognac: 40 vol-%', '10')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Ascorbinsäure: trocken', '12')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Spülmittel: Regular, 750 ml', '16')))->toBe('regulaer')
        // Regel 17 v1 (Georg 16.11.2026)
        ->and($this->svc->regelFuerGp(($this->gp)('Weißwein: trocken', '10')))->toBe('regulaer')                 // Kochwein
        ->and($this->svc->regelFuerGp(($this->gp)('Schweinebauch: frisch', '04')))->toBe('ermaessigt')           // „wein“ in Schwein zählt nicht
        ->and($this->svc->regelFuerGp(($this->gp)('Rotweinessig', '11')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Zitronensaft: frisch', '10')))->toBe('regulaer')               // Nr. 32: Säfte auch als Zutat
        ->and($this->svc->regelFuerGp(($this->gp)('Hummer: gekocht, TK', '05')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Haferdrink: konserviert', '06')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Kokosmilch: konserviert', '10')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Milchmischgetränk: Kakao, konserviert / (Milram)', '15')))->toBe('regulaer')   // Milchanteil ≥ 75 % nicht belegt
        ->and($this->svc->regelFuerGp(($this->gp)('Buttermilch: frisch', '15')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Mineralwasser: still, Glasflasche 0,75 l', '15')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Fuze Tea Grüner Tee Mango Kamille: PET-Flasche 0,4 l', '15')))->toBe('regulaer')   // fertiges Teegetränk
        ->and($this->svc->regelFuerGp(($this->gp)('Ananas: Stücke, konserviert, im eigenen Saft', '02')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Dip: Barbecue, Bier, frisch / (Starline)', '10', 'Dip')))->toBe('ermaessigt')     // Geschmack entscheidet nicht
        ->and($this->svc->regelFuerGp(($this->gp)('Fruchtkaviar: Himbeere, Perle 5 mm, konserviert', '13')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Forellenkaviar: frisch', '05')))->toBe('regulaer')
        ->and($this->svc->regelFuerGp(($this->gp)('Ravioli Hummer: TK / (Achenbach)', '08')))->toBe('ermaessigt')
        ->and($this->svc->regelFuerGp(($this->gp)('Thunfisch: konserviert, in Öl', '05')))->toBe('ermaessigt');
});

it('Artikel: eigene Klasse vor GP-Klasse; Necta-Zahl nur 7/19/0 zuordenbar, Widerspruch erkannt', function () {
    $gp = (object) ['name' => 'Dinkel: trocken', 'main_ingredient_display' => 'Dinkel', 'commodity_group_code' => '07', 'sub_category' => null, 'vat_profile_key' => 'ermaessigt'];

    expect($this->svc->klasseLa((object) ['vat_profile_key' => null, 'vat' => 19], $gp))->toBe('ermaessigt')
        ->and($this->svc->klasseLa((object) ['vat_profile_key' => 'regulaer', 'vat' => 7], $gp))->toBe('regulaer')
        ->and($this->svc->nectaKlasse(10))->toBeNull()
        ->and($this->svc->nectaKlasse('19.00'))->toBe('regulaer')
        ->and($this->svc->nectaWiderspruch((object) ['vat_profile_key' => null, 'vat' => 19], $gp))->toBeTrue()
        ->and($this->svc->nectaWiderspruch((object) ['vat_profile_key' => null, 'vat' => 7], $gp))->toBeFalse()
        ->and($this->svc->nectaWiderspruch((object) ['vat_profile_key' => null, 'vat' => null], $gp))->toBeNull();
});

it('Satz kommt aus den Team-Einstellungen: ändert sich der Satz, ändert er sich für alle Artikel der Klasse', function () {
    expect($this->svc->satz($this->rootTeam, 'ermaessigt'))->toBe(7.0)
        ->and($this->svc->satz($this->rootTeam, 'regulaer'))->toBe(19.0)
        ->and($this->svc->satz($this->rootTeam, 'ohne'))->toBe(0.0);

    $s = \Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting::firstOrNew(['team_id' => $this->rootTeam->id]);
    $s->vat_defaults = ['regulaer' => 19.0, 'ermaessigt' => 5.0, 'default_satz' => 'ermaessigt'];
    $s->save();

    expect(app(SteuerklasseService::class)->satz($this->rootTeam->fresh(), 'ermaessigt'))->toBe(5.0);
})->skip(fn () => ! class_exists(\Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting::class), 'Team-Settings-Modell nicht vorhanden');

it('Backfill: Trockenlauf ändert nichts, --apply setzt die Klasse aus der Regel, --zurueck nimmt sie zurück', function () {
    $dir = sys_get_temp_dir() . '/stk_' . uniqid();
    File::ensureDirectoryExists($dir);
    $dinkel = $this->makeGp($this->rootTeam, 'Dinkel: trocken');
    $dinkel->forceFill(['commodity_group_code' => '07', 'main_ingredient_display' => 'Dinkel'])->save();
    $bier = $this->makeGp($this->rootTeam, 'Pils: 4,9 vol-%, Glasflasche 0,5 l');
    $bier->forceFill(['commodity_group_code' => '15', 'main_ingredient_display' => 'Pils'])->save();
    $schon = $this->makeGp($this->rootTeam, 'Ascorbinsäure: trocken');
    $schon->forceFill(['commodity_group_code' => '12', 'vat_profile_key' => 'ermaessigt'])->save();   // von Hand gesetzt → bleibt

    $this->artisan('foodalchemist:steuerklasse-backfill', ['--bericht' => $dir])->assertSuccessful()->expectsOutputToContain('Trockenlauf');
    expect(DB::table('foodalchemist_gps')->where('id', $dinkel->id)->value('vat_profile_key'))->toBeNull();

    $this->artisan('foodalchemist:steuerklasse-backfill', ['--bericht' => $dir, '--apply' => true])->assertFailed();   // ohne Backup

    $backup = $dir . '/b.sql';
    file_put_contents($backup, str_repeat('x', 2048));
    $this->artisan('foodalchemist:steuerklasse-backfill', ['--bericht' => $dir, '--apply' => true, '--backup-nachweis' => $backup])->assertSuccessful();
    expect(DB::table('foodalchemist_gps')->where('id', $dinkel->id)->value('vat_profile_key'))->toBe('ermaessigt')
        ->and(DB::table('foodalchemist_gps')->where('id', $bier->id)->value('vat_profile_key'))->toBe('regulaer')
        ->and(DB::table('foodalchemist_gps')->where('id', $schon->id)->value('vat_profile_key'))->toBe('ermaessigt');

    $this->artisan('foodalchemist:steuerklasse-backfill', ['--zurueck' => glob($dir . '/*_steuerklasse_protokoll.json')[0]])->assertSuccessful();
    expect(DB::table('foodalchemist_gps')->where('id', $dinkel->id)->value('vat_profile_key'))->toBeNull()
        ->and(DB::table('foodalchemist_gps')->where('id', $schon->id)->value('vat_profile_key'))->toBe('ermaessigt');
    File::deleteDirectory($dir);
});

it('Artikelmaske: Steuerklasse statt freier Zahl — speichert die Klasse, zeigt den wirksamen Satz und die abweichende Lieferantenangabe', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $sup = \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'BOS']);
    $la = \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'Dinkelkörner 1 kg', 'qty' => 1, 'unit_code' => 'kg', 'vat' => 19]);
    $gp = $this->makeGp($this->rootTeam, 'Dinkel: trocken');
    $gp->forceFill(['commodity_group_code' => '07', 'main_ingredient_display' => 'Dinkel', 'vat_profile_key' => 'ermaessigt'])->save();
    \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure::create(['supplier_item_id' => $la->id, 'gp_id' => $gp->id]);

    $c = \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Suppliers\ItemModal::class)->call('oeffnen', $la->id);
    expect($c->html())->toContain('data-steuerklasse')->toContain('Wirksam: ermäßigt (7 %), vom Grundprodukt')->toContain('Lieferantenangabe: 19 % (weicht ab)')
        ->not->toContain('placeholder="7 oder 19"');

    $c->set('eigenschaften.vat_profile_key', 'regulaer')->call('speichern')->assertSet('fehler', null);
    expect($la->fresh()->vat_profile_key)->toBe('regulaer')
        ->and((float) $la->fresh()->vat)->toBe(19.0);   // Lieferantenangabe bleibt unverändert
});
