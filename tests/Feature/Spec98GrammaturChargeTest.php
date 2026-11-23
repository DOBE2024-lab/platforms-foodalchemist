<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\RecipeGeneratorService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B (h), Entscheid Dominique 23.11.2026 („ok fixe die Ki“): Liegt die Portion eines Gerichts außerhalb 20–3000 g, wirken
 * die Mengen wie eine Charge — Hinweis am Gericht, in der Generator- und der MCP-Antwort, kein Auto-VK. Abgeleitet, keine Spalte:
 * er verschwindet, sobald die Mengen im Band liegen oder der VK bewusst fixiert ist.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->svc = app(DarreichungService::class);
    $filet = ($this->gp)('Rinderfilet', 30.00);
    [$this->r, $z] = ($this->rezept)('filet-charge', '[HG] Rinderfilet | Jus', [[$filet, 4000]]);   // Charge: 4 kg „je Portion“
    $this->zeile = $z['Rinderfilet'];
    $this->std = ($this->darreichung)($this->r, 'teller', ['is_standard' => true, 'menge_vom_standard' => true]);
    $this->svc->recomputeFuerRezept($this->r->id);
    $this->neu = function (float $gramm) {
        $this->zeile->update(['quantity' => $gramm]);
        app(RecipeRecomputeService::class)->recomputePipeline($this->r->id);
        $this->svc->recomputeFuerRezept($this->r->id);
    };
});

it('Chargen-Mengen: Hinweis, kein Auto-VK; der EK rechnet weiter', function () {
    $s = $this->std->fresh();
    expect($this->svc->chargenHinweis($this->r->fresh()))->toBe(DarreichungService::CHARGE_HINWEIS)
        ->and((float) $s->ek_portion)->toBe(120.0)
        ->and($s->calculated_sales_net)->toBeNull()
        ->and($s->sales_net)->toBeNull();
});

it('Mengen korrigiert (180 g): Hinweis weg, Auto-VK da; zu klein (15 g) wieder Hinweis', function () {
    ($this->neu)(180);
    $s = $this->std->fresh();
    expect($this->svc->chargenHinweis($this->r->fresh()))->toBeNull()
        ->and((float) $s->calculated_sales_net)->toBeGreaterThan(0.0)
        ->and((float) $s->sales_net)->toBeGreaterThan(0.0);

    ($this->neu)(15);
    expect($this->svc->chargenHinweis($this->r->fresh()))->toBe(DarreichungService::CHARGE_HINWEIS)
        ->and($this->std->fresh()->sales_net)->toBeNull();
});

it('bewusst gesetzter VK: Hinweis weg, der VK bleibt', function () {
    $this->svc->aktualisieren($this->rootTeam, $this->std->id, ['price_mode' => 'fixed', 'sales_net' => 25, 'price_override_reason' => 'Buffet-Platte, bewusst']);

    expect($this->svc->chargenHinweis($this->r->fresh()))->toBeNull()
        ->and((float) $this->std->fresh()->sales_net)->toBe(25.0);
});

it('Gericht-Modal zeigt den Hinweis an der Darreichung', function () {
    $html = Livewire::test(VkModal::class)->call('oeffnen', $this->r->id)->html();
    expect($html)->toContain('data-dar-charge')->toContain('Mengen wirken wie eine Charge');

    ($this->neu)(180);
    expect(Livewire::test(VkModal::class)->call('oeffnen', $this->r->id)->html())->not->toContain('data-dar-charge');
});

it('Generator: Chargen-Antwort ohne Ziel → Hinweis; mit Ziel skaliert ohne Hinweis; normale Antwort ohne Hinweis', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $gen = fn (array $zutaten, array $parameter = []) => app(RecipeGeneratorService::class)
        ->generiere($this->rootTeam, 'Test', $parameter, ['name' => '[HG] Rinderfilet | Kürbis', 'zutaten' => $zutaten], vkModus: true);
    $charge = [['text' => 'Rinderfilet', 'quantity' => 12000, 'unit' => 'g'], ['text' => 'Kürbis', 'quantity' => 9600, 'unit' => 'g']];

    $ohneZiel = $gen($charge);
    expect($ohneZiel['statistik']['portion'])->toBeNull()
        ->and($ohneZiel['statistik']['charge_hinweis'])->toBe(DarreichungService::CHARGE_HINWEIS);

    $mitZiel = $gen($charge, ['ziel_portion_g' => 350]);
    expect($mitZiel['statistik']['portion']['skaliert'])->toBeTrue()
        ->and($mitZiel['statistik']['charge_hinweis'])->toBeNull();

    $normal = $gen([['text' => 'Rinderfilet', 'quantity' => 160, 'unit' => 'g'], ['text' => 'Kürbis', 'quantity' => 120, 'unit' => 'g']]);
    expect($normal['statistik']['charge_hinweis'])->toBeNull();
});
