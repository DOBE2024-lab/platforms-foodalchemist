<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (S4-0): Charakterisierung vor dem Zerlegen von FoodbookService, für die Methoden ohne eigenen Test
 * (Staffelpreis, Vorschau-Snapshot, CRM-Suche ohne Eingabe, Cover entfernen). Die Werte sind der Stand auf main 0fcb50bad.
 * Der Schnitt verschiebt nur; ändert sich hier ein Wert, hat sich Verhalten geändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['filesystems.default' => 'public']);   // wie FoodbookBrandingTest: Bilder laufen über Core-ContextFiles auf der Standard-Disk
    Storage::fake('public');
    $this->actingAs($this->makeUser($this->rootTeam, 'Foodbook'));
    $this->svc = app(FoodbookService::class);
    $this->fb = $this->makeFoodbook($this->rootTeam, 'Schnitt-Buch', ['status' => 'entwurf', 'personen' => 40]);
    $this->kap = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Empfang']);
});

it('resolveStaffel: ohne Staffel 0, ohne Pax die kleinste Stufe, sonst die höchste erreichte Stufe, darunter die kleinste', function () {
    $block = $this->makeFoodbookBlock($this->kap, ['type' => 'text', 'label' => 'Pauschale']);
    expect($this->svc->resolveStaffel($block, 50))->toBe(0.0);

    $this->svc->setStaffel($this->rootTeam, $block->id, [
        ['min_persons' => 50, 'price' => 8.0],
        ['min_persons' => 1, 'price' => 10.0],
        ['min_persons' => 100, 'price' => 7.0],
    ]);
    $block = $block->fresh();

    expect($this->svc->resolveStaffel($block, null))->toBe(10.0)
        ->and($this->svc->resolveStaffel($block, 20))->toBe(10.0)
        ->and($this->svc->resolveStaffel($block, 50))->toBe(8.0)
        ->and($this->svc->resolveStaffel($block, 99))->toBe(8.0)
        ->and($this->svc->resolveStaffel($block, 150))->toBe(7.0)
        ->and($this->svc->resolveStaffel($block, 0))->toBe(10.0);   // unter jeder Stufe → kleinste
});

it('Vorschau-Snapshot: ohne Stand null; Aktualisieren speichert dokumentDaten ohne „fb“ mit Version 1, Lesen hängt snapshot_at an', function () {
    expect($this->svc->vorschauSnapshot($this->fb->fresh()))->toBeNull();

    $neu = $this->svc->vorschauSnapshotAktualisieren($this->rootTeam, $this->fb->id);
    $dok = $this->svc->dokumentDaten($this->rootTeam, $this->fb->fresh());

    expect($neu)->not->toHaveKey('fb')
        ->and($neu['snapshot_version'])->toBe(1)
        ->and(array_keys($neu))->toBe([...array_values(array_diff(array_keys($dok), ['fb'])), 'snapshot_version']);

    $gelesen = $this->svc->vorschauSnapshot($this->fb->fresh());
    expect($gelesen)->toHaveKey('snapshot_at')
        ->and($gelesen['snapshot_at'])->not->toBeNull()
        ->and($gelesen['snapshot_version'])->toBe(1);
});

it('CRM-Suche: leere Eingabe liefert nichts; crmVerfuegbar folgt der CRM-Klasse', function () {
    expect($this->svc->sucheFirmen('   ')->all())->toBe([])
        ->and($this->svc->sucheKontakte('')->all())->toBe([])
        ->and($this->svc->crmVerfuegbar())->toBe(class_exists(\Platform\Crm\Services\CompanyLinkService::class));
});

it('clearCover räumt Datei und Spalten wie clearLogo', function () {
    $pfad = $this->svc->storeCover($this->rootTeam, $this->fb->id, UploadedFile::fake()->image('cover.png', 60, 40));
    Storage::disk('public')->assertExists($pfad);

    $nach = $this->svc->clearCover($this->rootTeam, $this->fb->id);

    Storage::disk('public')->assertMissing($pfad);
    expect($nach->cover_image_path)->toBeNull()
        ->and($nach->cover_context_file_id)->toBeNull();
});
