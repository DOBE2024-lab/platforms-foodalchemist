<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\DarreichungsZeitService;
use Platform\FoodAlchemist\Services\ProductionTimeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Zeitvorschau der Darreichung (Entscheid Dominique 19.11.2026): drei Werte statt zwei — Herstellung (Basisrezepte, Rechnung des
 * Planungsblatts), Fertigstellen in der Küche, Service vor Ort — plus „Küche gesamt“; Portionenzahl editierbar, nur Vorschau.
 *
 * Fixture: Gericht mit einer Komponente „Jus: Kalb“ (100 g je Portion, Ansatz 1 kg, Rüsten 10 min, Ansatz 20 min, 2 min/kg,
 * Standzeit 30 min). Topf-Deckel Team 20 kg. Darreichung Teller: 30 s Küche je Portion, 6 s vor Ort je Portion.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_batch_max_kg' => 20]);

    $this->jus = $this->makeRecipe($this->rootTeam, 'Jus: Kalb');
    $this->makeIngredient($this->jus, 'Kalbsknochen', $this->makeGp($this->rootTeam, 'Kalbsknochen'), '1000');
    $this->jus->update(['yield_kg' => 1.0, 'setup_time_min' => 10, 'work_time_min' => 20,
        'variable_work_time_min' => 2, 'variable_work_time_basis' => 'kg', 'standzeit_min' => 30]);

    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'zeitvorschau-gericht', 'name' => 'Kalbsbacke',
        'is_sales_recipe' => true, 'status' => 'draft', 'yield_kg' => 0.1,
    ]);
    $this->makeIngredient($this->gericht, 'Jus: Kalb', null, '100')->update(['referenced_recipe_id' => $this->jus->id]);
    $this->gericht->update(['yield_kg' => 0.1]);

    $form = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->dar = app(DarreichungService::class)->anlegen($this->rootTeam, $this->gericht->id, $form->id, ['unit_count' => 1]);
    app(DarreichungService::class)->aktualisieren($this->rootTeam, $this->dar->id, ['arbeitszeiten' => [
        ['use_case' => 'anrichten_teller', 'basis' => 'portion', 'ort' => 'kueche', 'wert' => 30],
        ['use_case' => 'anrichten_teller', 'basis' => 'portion', 'ort' => 'vor_ort', 'wert' => 6],
    ]]);

    /** Text der Kachel `data-zeit=…` aus dem gerenderten Modal */
    $this->kachel = function (string $html, string $zeit): string {
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $knoten = (new DOMXPath($dom))->query('//*[@data-zeit="' . $zeit . '"]');

        if ($knoten->length !== 1) {
            return "fehlt ({$knoten->length})";
        }
        $teile = [];
        foreach ($knoten->item(0)->childNodes as $kind) {
            $teile[] = trim($kind->textContent);
        }

        return preg_replace('/\s+/u', ' ', trim(implode(' ', array_filter($teile, fn ($t) => $t !== ''))));
    };
});

it('Herstellung = Summe der Basisrezept-Zeiten aus ProductionTimeService für dieselbe Menge', function () {
    foreach ([100.0 => 50.0, 250.0 => 100.0] as $portionen => $soll) {
        $h = app(\Platform\FoodAlchemist\Services\PlanungsblattService::class)->darreichungsHerstellung($this->rootTeam, $this->gericht->fresh(), $this->dar->fresh(), $portionen);
        // Jus-Bedarf: Portionen × 100 g ÷ 1 kg Ansatz → Ansätze; dieselbe Rechnung wie Planungsblatt/Auftrag
        $pts = app(ProductionTimeService::class)->calculateForBatches($this->rootTeam, $this->jus->fresh(), $portionen * 0.1);
        expect($h['herstellung_minuten'])->toBe(round($pts['active_person_minutes'], 1))
            ->and($h['herstellung_minuten'])->toBe($soll)      // 100 P.: 10 + 20 × 1 + 2 × 10 kg = 50 · 250 P.: 10 + 20 × 2 + 2 × 25 = 100
            ->and($h['herstellung_rezepte'])->toBe([['name' => 'Jus: Kalb', 'minuten' => $soll]])
            ->and($h['passiv_minuten'])->toBe(30.0);           // Standzeit Jus, nicht in den Personenminuten
    }
});

it('zeigt drei Werte und Küche gesamt; Überschrift „Zeitvorschau“, Portionen 100 vorbelegt', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->assertSet('darVorschauPortionen', 100)->html();

    expect(($this->kachel)($html, 'herstellung'))->toBe('Herstellung (Basisrezepte) 50,0 min Gepflegt am Basisrezept')
        ->and(($this->kachel)($html, 'fertigstellen'))->toBe('Fertigstellen in der Küche 50,0 min Arbeitsschritte dieser Darreichung')
        ->and(($this->kachel)($html, 'vor-ort'))->toBe('Service vor Ort 10,0 min Serviceplanung & Servicelohn')
        ->and(($this->kachel)($html, 'kueche-gesamt'))->toBe('Küche gesamt · 100 Portionen 100,0 min')
        ->and(($this->kachel)($html, 'passiv'))->toBe('Passiv (Stand- und Gerätezeit) · nicht in den Personenminuten 30,0 min')
        ->and($html)->toContain('Zeitvorschau', 'data-dar-vorschau-portionen', 'Nur Vorschau, wird nicht gespeichert')
        ->not->toContain('Gespeicherte Zeitvorschau', 'Küchenarbeit', 'Arbeit vor Ort');
});

it('Portionenzahl rechnet alle Werte neu und wird nicht gespeichert', function () {
    $vorher = $this->dar->fresh()->only(['arbeitszeiten', 'updated_at']);
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->set('darVorschauPortionen', '250')->html();

    expect(($this->kachel)($html, 'herstellung'))->toContain('100,0 min')
        ->and(($this->kachel)($html, 'fertigstellen'))->toContain('125,0 min')
        ->and(($this->kachel)($html, 'vor-ort'))->toContain('25,0 min')
        ->and(($this->kachel)($html, 'kueche-gesamt'))->toBe('Küche gesamt · 250 Portionen 225,0 min')
        ->and($html)->toContain('Service-Grundlohn für 250 Portionen')
        ->and($this->dar->fresh()->only(['arbeitszeiten', 'updated_at']))->toEqual($vorher);
});

it('ungültige Portionenzahl fällt auf 100 zurück, Komma ist erlaubt', function () {
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    expect(($this->kachel)($lw->set('darVorschauPortionen', 'abc')->html(), 'kueche-gesamt'))->toBe('Küche gesamt · 100 Portionen 100,0 min')
        ->and(($this->kachel)($lw->set('darVorschauPortionen', '0')->html(), 'kueche-gesamt'))->toStartWith('Küche gesamt · 1 Portion ')
        ->and(($this->kachel)($lw->set('darVorschauPortionen', '12,5')->html(), 'kueche-gesamt'))->toStartWith('Küche gesamt · 12,5 Portionen');
});

it('ungespeicherte Arbeitsschritte: Hinweis „Noch nicht gespeichert“ bleibt', function () {
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id);
    expect($lw->html())->not->toContain('Noch nicht gespeichert');
    $lw->set('darZeitForm.' . $this->dar->id . '.0.wert', '45');
    expect($lw->html())->toContain('Noch nicht gespeichert');
});
