<?php

use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (S3) Charakterisierung vor dem Schnitt „Ausgabe“: druckOptik hatte keinen eigenen Test. Die Werte sind der Stand
 * auf main 30833978 (Paletten aus PresentationDesignService, Abdunkeln auf 4,5 : 1 gegen Weiß). Der Schnitt verschiebt nur;
 * ändert sich hier ein Wert, hat sich Verhalten geändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(SpeiseplanService::class);
    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'Kantine Druck', 'start_date' => '2026-10-05']);
});

it('druckOptik: dunkles Standard-Design (Kiosk) liefert nur den abgedunkelten Akzent, Text und Nebentext fest', function () {
    $o = $this->svc->druckOptik($this->rootTeam, $this->plan->fresh());

    expect($o)->toBe([
        'logo' => null,
        'footer' => null,
        'akzent' => '#876f47',
        'band' => '#876f47',
        'text' => '#1a1712',
        'muted' => '#5f5850',
        'serif' => true,
        'schrift_kopf' => '"PT Serif", "DejaVu Serif", Georgia, serif',
        'schrift_text' => '"DejaVu Sans", Arial, sans-serif',
    ]);
});

it('druckOptik: helles Design übernimmt Text/Nebentext lesbar, Bandfarbe wird abgedunkelt, Footer getrimmt', function () {
    $this->plan->forceFill(['presentation_design' => 'editorial', 'band_color' => '#ff0000', 'footer_text' => '  Gute Pause  '])->save();

    $o = $this->svc->druckOptik($this->rootTeam, $this->plan->fresh());

    expect($o['akzent'])->toBe('#6d28d9')
        ->and($o['band'])->toBe('#e50000')
        ->and($o['text'])->toBe('#1a1712')
        ->and($o['muted'])->toBe('#7c746c')
        ->and($o['footer'])->toBe('Gute Pause')
        ->and($o['serif'])->toBeTrue();
});

it('druckOptik: ungültige Bandfarbe fällt auf den Akzent zurück; leerer Footer bleibt null', function () {
    $this->plan->forceFill(['presentation_design' => 'editorial', 'band_color' => 'rot', 'footer_text' => '   '])->save();

    $o = $this->svc->druckOptik($this->rootTeam, $this->plan->fresh());

    expect($o['band'])->toBe('#6d28d9')->and($o['footer'])->toBeNull();
});
