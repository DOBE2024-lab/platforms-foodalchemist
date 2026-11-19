<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Support\Labels;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 1.1d (U1): Die Kopfkachel „Allergenangaben“ im GP-Modal zeigte bei einem GP ohne belegte
 * Allergene den Rohwert `none` — englisch und liest sich wie „allergenfrei“. Das Label kommt aus
 * der zentralen Quelle ({@see Labels::konfidenz}) und lautet „Unbekannt“ — mit Warn-Ton, damit es nie wie
 * „allergenfrei“ aussieht (GP-Modal und GP-Detailpanel).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

/** Ausschnitt einer Kennzahl-Kachel: ab ihrem `data-kpi` bis zur nächsten Kachel (oder 1500 Zeichen). */
function allergenKachel(string $html, string $kpi): string
{
    $start = strpos($html, 'data-kpi="' . $kpi . '"');
    expect($start)->not->toBeFalse();
    $rest = substr($html, $start + 1);
    $ende = strpos($rest, 'data-kpi="');

    return substr($html, $start, $ende === false ? 1500 : min($ende + 1, 1500));
}

it('Labels::konfidenz übersetzt none als Unbekannt, nie als Rohwert', function () {
    expect(Labels::konfidenz('none'))->toBe('Unbekannt');
});

it('GP-Modal: Kopfkachel Allergenangaben zeigt Unbekannt statt none, wenn kein Artikel Allergene belegt', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zander: Filet, TK');

    $html = Livewire::test(GpModal::class)
        ->call('oeffnen', $gp->id)
        ->assertSeeInOrder(['Allergenangaben', 'Unbekannt'])
        ->assertDontSeeHtml('>none<')
        ->html();

    expect($html)->toMatch('/data-tone="warn"[^>]*data-kpi="allergen"/');
    $kachel = allergenKachel($html, 'allergen');
    expect($kachel)->toContain('Unbekannt')->not->toMatch('/\\bnone\\b/');
});

it('GP-Detailpanel: Kennzahl Allergenangaben zeigt Unbekannt mit Warn-Ton statt none', function () {
    $gp = $this->makeGp($this->rootTeam, 'Zander: Filet, TK');

    $html = Livewire::test(DetailPanel::class, ['gpId' => $gp->id])
        ->assertSeeInOrder(['Allergenangaben', 'Unbekannt'])
        ->assertDontSeeHtml('>none<')
        ->html();

    expect($html)->toMatch('/data-tone="warn"[^>]*data-kpi="allergen-konfidenz"/');
    $kachel = allergenKachel($html, 'allergen-konfidenz');
    expect($kachel)->toContain('Unbekannt')->not->toMatch('/\\bnone\\b/');
});
