<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Support\BildUpload;
use Platform\FoodAlchemist\Support\DateiUpload;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bild-Upload einheitlich 14 MB + deutsche Meldungen (20.11.2026).
 *
 * Anlass: Titelbild im Foodbook (Tab „Branding & Präsentation“) mit 9,2 MB scheiterte an der alten Modul-Regel
 * image|max:8192, unter dem Feld stand roh „validation.max.file“ (Host-App ohne lang/de). Livewire selbst lässt auf io
 * bis 15 MB durch (temporary_file_upload.rules max:15360) — hier genauso gesetzt, damit die FA-Regel geprüft wird.
 */
beforeEach(function () {
    config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:15360']]);
    config(['filesystems.default' => 'public']);
    Storage::fake('public');

    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    Livewire::test(FoodbooksIndex::class)->set('neuName', 'Testbuch')->call('neuAnlegen');
    $this->fb = FoodAlchemistFoodbook::first();
});

/** @return list<string> alle Fehlermeldungen der Komponente */
function bildUploadFehler($komponente): array
{
    return $komponente->errors()->all();
}

it('Titelbild mit 9,2 MB wird angenommen und gespeichert', function () {
    $bild = UploadedFile::fake()->image('titel.jpg', 1200, 800)->size(9420);

    $c = Livewire::test(FoodbooksIndex::class)->call('waehle', $this->fb->id)->set('coverUpload', $bild);

    $c->assertHasNoErrors();
    $pfad = $this->fb->refresh()->cover_image_path;
    expect($pfad)->not->toBeNull();
    Storage::disk('public')->assertExists($pfad);
});

it('Titelbild mit 15 MB wird abgelehnt — deutsche Meldung mit 14 MB, kein Rohschlüssel', function () {
    $bild = UploadedFile::fake()->image('titel.jpg', 1200, 800)->size(15360);

    $c = Livewire::test(FoodbooksIndex::class)->call('waehle', $this->fb->id)->set('coverUpload', $bild);

    $c->assertHasErrors(['coverUpload']);
    $text = implode(' ', bildUploadFehler($c));
    expect($text)->toContain('14 MB')
        ->and($text)->toBe('Das Bild darf höchstens 14 MB groß sein.')
        ->and($text)->not->toContain('validation.');
    expect($this->fb->refresh()->cover_image_path)->toBeNull();
});

it('Nicht-Bild am Titelbild → deutsche Typ-Meldung', function () {
    $c = Livewire::test(FoodbooksIndex::class)->call('waehle', $this->fb->id)
        ->set('coverUpload', UploadedFile::fake()->create('titel.pdf', 100, 'application/pdf'));

    expect(bildUploadFehler($c))->toBe(['Bitte eine Bilddatei wählen (JPG, PNG oder WebP).']);
});

it('Titelbild läuft über die Verkleinerung (lange Kante > maxKante → verkleinert)', function () {
    $c = Livewire::test(FoodbooksIndex::class)->call('waehle', $this->fb->id)
        ->set('coverUpload', UploadedFile::fake()->image('riesig.jpg', 4800, 3600));

    $c->assertHasNoErrors();
    $inhalt = Storage::disk('public')->get($this->fb->refresh()->cover_image_path);
    expect(max(array_slice(getimagesizefromstring($inhalt), 0, 2)))->toBe(\Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService::maxKante());
});

it('Livewires Vorab-Prüfung (über 15 MB) erscheint deutsch statt als Rohschlüssel', function () {
    $roh = json_encode(['errors' => ['files.0' => ['validation.max.file']]]);

    $c = Livewire::test(FoodbooksIndex::class)->call('waehle', $this->fb->id)->call('_uploadErrored', 'coverUpload', $roh, false);
    expect(bildUploadFehler($c))->toBe(['Die Datei ist zu groß (höchstens 15 MB je Datei).']);

    $c = Livewire::test(FoodbooksIndex::class)->call('waehle', $this->fb->id)->call('_uploadErrored', 'coverUpload', null, false);
    expect(implode(' ', bildUploadFehler($c)))->toStartWith('Die Datei konnte nicht hochgeladen werden.')
        ->not->toContain('failed to upload');
});

it('eine Grenze, Meldungen in MB', function () {
    expect(BildUpload::MAX_KB)->toBe(14336)
        ->and(BildUpload::regel())->toBe('image|max:14336')
        ->and(BildUpload::regel(true))->toBe('required|image|max:14336')
        ->and(BildUpload::meldungen('x.*')['x.*.max'])->toBe('Das Bild darf höchstens 14 MB groß sein.')
        ->and(\Platform\FoodAlchemist\Services\Rezept\RezeptFotoService::MAX_KB)->toBe(BildUpload::MAX_KB)
        ->and(DateiUpload::meldungen('datei', 10240, 'CSV')['datei.max'])->toBe('Die Datei darf höchstens 10 MB groß sein.')
        ->and(DateiUpload::mb(1536))->toBe('1,5');
});

it('Wächter: kein hart codiertes »image|max:« in src/Livewire — Bild-Uploads nur über BildUpload', function () {
    $treffer = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Livewire', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if ($datei->getExtension() !== 'php') {
            continue;
        }
        foreach (file($datei->getPathname()) as $nr => $zeile) {
            if (preg_match('/[\'"|]image\s*\|[^\'"]*max:|max:\d+\s*\|\s*image\b/', $zeile) === 1) {
                $treffer[] = substr($datei->getPathname(), strlen(dirname(__DIR__, 2)) + 1) . ':' . ($nr + 1);
            }
        }
    }
    expect($treffer)->toBe([], 'Bild-Upload-Regel hart codiert — BildUpload::pruefen()/regel() nutzen');
});
