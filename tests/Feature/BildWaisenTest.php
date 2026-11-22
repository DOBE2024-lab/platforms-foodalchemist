<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Services\BildWaisenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Waisen-Bilddateien (Folge aus dem Bild-Löschschutz #901/#907): Bericht, Quarantäne mit Journal, Rückweg. Die Nutzung prüft nur
 * FoodAlchemistMediaService::wirdGenutzt — Datensatz und veröffentlichter Snapshot halten eine Datei; zu junge Dateien zählen nicht.
 */
function waisDatei(int $teamId, string $pfad, int $tageAlt = 60, string $mime = 'image/webp', string $typ = 'foodalchemist.foodbook'): ContextFile
{
    Storage::disk('public')->put($pfad, 'inhalt-' . $pfad);
    $d = ContextFile::create([
        'token' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $teamId, 'user_id' => 1, 'context_type' => $typ, 'context_id' => 1,
        'disk' => 'public', 'path' => $pfad, 'file_name' => basename($pfad), 'original_name' => basename($pfad), 'mime_type' => $mime,
        'file_size' => strlen('inhalt-' . $pfad), 'keep_original' => false,
    ]);
    DB::table('context_files')->where('id', $d->id)->update(['created_at' => now()->subDays($tageAlt)]);

    return $d;
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Bild'));
    Storage::fake('public');
    $this->journale = sys_get_temp_dir() . '/fa-waisen-test-' . uniqid();
    $this->svc = app(BildWaisenService::class);

    $t = $this->rootTeam->id;
    $this->genutzt = waisDatei($t, 'foodalchemist/branding/1/logo.webp');
    $this->makeFoodbook($this->rootTeam, 'Katalog', ['logo_context_file_id' => $this->genutzt->id, 'logo_path' => $this->genutzt->path]);

    $this->snapshot = waisDatei($t, 'foodalchemist/branding/2/cover.webp');   // nur noch im veröffentlichten Snapshot (Republish ohne Bild davor)
    $this->makeFoodbook($this->rootTeam, 'Veröffentlicht')->forceFill(['presentation_snapshot_json' => ['cover' => ['context_file_id' => $this->snapshot->id, 'path' => $this->snapshot->path]]])->save();

    $this->waise = waisDatei($t, 'foodalchemist/branding/3/alt.webp');
    $this->jung = waisDatei($t, 'foodalchemist/branding/4/neu.webp', 2);

    $this->beleg = waisDatei($t, 'foodalchemist/lieferscheine/foto.webp', 60, 'image/webp', 'foodalchemist.delivery_note');
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Chefs Culinar']);
    DB::table('foodalchemist_delivery_notes')->insert(['team_id' => $t, 'supplier_id' => $lieferant->id, 'attachment_context_file_id' => $this->beleg->id, 'delivered_on' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now()]);
});

afterEach(fn () => File::deleteDirectory($this->journale));

it('meldet nur die echte Waise: Datensatz, Snapshot, Beleg und zu junge Dateien bleiben außen vor', function () {
    $funde = $this->svc->suche(30);

    expect(array_column($funde['kontextdatei'], 'context_file_id'))->toBe([$this->waise->id])
        ->and($funde['zeile_ohne_datei'])->toBe([]);
});

it('Altpfad ohne Nutzung steht als Hinweis im Bericht, genutzter Altpfad nicht', function () {
    Storage::disk('public')->put('foodalchemist/rezepte/alt-genutzt.jpg', 'x');
    Storage::disk('public')->put('foodalchemist/rezepte/alt-waise.jpg', 'xy');
    $this->makeRecipe($this->rootTeam, 'Rinderbacke', ['image_path' => 'foodalchemist/rezepte/alt-genutzt.jpg']);
    touch(Storage::disk('public')->path('foodalchemist/rezepte/alt-waise.jpg'), now()->subDays(60)->getTimestamp());
    touch(Storage::disk('public')->path('foodalchemist/rezepte/alt-genutzt.jpg'), now()->subDays(60)->getTimestamp());

    expect(array_column($this->svc->suche(30)['altpfad'], 'pfad'))->toBe(['foodalchemist/rezepte/alt-waise.jpg']);
});

it('Bericht schreibt nichts; --json liefert Anzahl, Bytes, Beispiele je Art', function () {
    $this->artisan('foodalchemist:waisen --json')->assertSuccessful();
    $this->artisan('foodalchemist:waisen')->expectsOutputToContain('nur Hinweis, wird nicht verschoben')->assertSuccessful();

    expect(Storage::disk('public')->exists($this->waise->path))->toBeTrue()
        ->and(File::isDirectory($this->journale))->toBeFalse();
});

it('Quarantäne verschiebt mit Journal, der Rückweg stellt den Ursprungszustand her', function () {
    $vorher = Storage::disk('public')->allFiles();
    $inhalt = Storage::disk('public')->get($this->waise->path);

    $q = $this->svc->quarantaene($this->svc->suche(30)['kontextdatei'], $this->journale);
    expect($q['verschoben'])->toBe(1)
        ->and(Storage::disk('public')->exists($this->waise->path))->toBeFalse()
        ->and(Storage::disk('public')->exists('fa-quarantaene/' . $q['lauf'] . '/' . $this->waise->path))->toBeTrue()
        ->and(Storage::disk('public')->exists($this->genutzt->path))->toBeTrue()
        ->and(ContextFile::query()->withoutGlobalScopes()->find($this->waise->id))->not->toBeNull()   // DB-Zeile bleibt
        ->and(json_decode(File::get($q['journal']), true)['eintraege'][0]['context_file_id'])->toBe($this->waise->id);

    $r = $this->svc->rueckweg($q['journal']);
    expect($r)->toBe(['zurueck' => 1, 'konflikte' => [], 'fehlend' => []])
        ->and(Storage::disk('public')->get($this->waise->path))->toBe($inhalt)
        ->and(array_values(array_filter(Storage::disk('public')->allFiles(), fn ($p) => ! str_starts_with($p, 'fa-quarantaene/'))))->toEqual($vorher);
});

it('Apply prüft erneut: zwischen Bericht und Verschieben wieder genutzt → bleibt liegen', function () {
    $kandidaten = $this->svc->suche(30)['kontextdatei'];
    $this->makeFoodbook($this->rootTeam, 'Neu verknüpft', ['cover_context_file_id' => $this->waise->id, 'cover_image_path' => $this->waise->path]);

    $q = $this->svc->quarantaene($kandidaten, $this->journale);
    expect($q['verschoben'])->toBe(0)->and($q['uebersprungen'])->toHaveCount(1)
        ->and(Storage::disk('public')->exists($this->waise->path))->toBeTrue();
});

it('Rückweg überschreibt nie: liegt am Ursprungsort schon eine Datei, ist das ein Konflikt', function () {
    $q = $this->svc->quarantaene($this->svc->suche(30)['kontextdatei'], $this->journale);
    Storage::disk('public')->put($this->waise->path, 'neue Datei');

    $r = $this->svc->rueckweg($q['journal']);
    expect($r['zurueck'])->toBe(0)->and($r['konflikte'])->toHaveCount(1)
        ->and(Storage::disk('public')->get($this->waise->path))->toBe('neue Datei');
});

it('Befehl mit --apply und --rueckweg', function () {
    $this->artisan('foodalchemist:waisen --apply --journal-ordner=' . $this->journale)->expectsOutputToContain('In Quarantäne: 1')->assertSuccessful();
    $journal = File::files($this->journale)[0]->getPathname();
    expect(Storage::disk('public')->exists($this->waise->path))->toBeFalse();

    $this->artisan('foodalchemist:waisen --rueckweg=' . $journal)->expectsOutputToContain('Zurückgelegt: 1')->assertSuccessful();
    expect(Storage::disk('public')->exists($this->waise->path))->toBeTrue();
});
