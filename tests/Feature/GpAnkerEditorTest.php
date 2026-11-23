<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Services\Pairing\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket J — Aroma-Anker am GP: Editor-Block (mehrere Anker, role kern|neben, sofort
 * gespeichert) + Detail-Panel-Chip mit „weitere GPs mit diesem Anker" (Reverse-Lookup, kein Graph).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->gp = $this->makeGp($this->rootTeam, 'Ratatouille-Test');
    $this->gp->update(['status' => 'approved']);
    $this->neuAnker = fn (string $name) => DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
        'uuid' => (string) Str::uuid(), 'team_id' => null,
        'slug' => Str::slug($name).'-'.bin2hex(random_bytes(3)), 'display_de' => $name,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('GP-Modal: Suche zeigt Kandidaten, Verknüpfen setzt den Anker mit Rolle, Chip erscheint', function () {
    $ankerId = ($this->neuAnker)('Eggplant-Test');

    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id);
    $c->set('gpAnkerSuche', 'Eggplant-Test');
    expect($c->html())->toContain('data-gp-anker-kandidat')->toContain('Eggplant-Test');

    $c->set('gpAnkerRolle', 'neben')->call('gpAnkerVerknuepfen', $ankerId);

    expect($c->html())->toContain('data-gp-anker-liste')->toContain('Eggplant-Test');
    $zeile = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $this->gp->id)->where('anchor_id', $ankerId)->first();
    expect($zeile)->not->toBeNull()
        ->and($zeile->role)->toBe('neben')
        ->and($zeile->source)->toBe('manual');
});

it('GP-Modal: mehrere Anker gleichzeitig erlaubt (je Rolle gedeckelt), Entfernen löst genau einen', function () {
    $a1 = ($this->neuAnker)('Eggplant');
    $a2 = ($this->neuAnker)('Tomato');
    $a3 = ($this->neuAnker)('Zucchini');
    app(PairingService::class)->setGpAnker($this->rootTeam, $this->gp->id, $a1, 'kern');
    app(PairingService::class)->setGpAnker($this->rootTeam, $this->gp->id, $a2, 'neben');
    app(PairingService::class)->setGpAnker($this->rootTeam, $this->gp->id, $a3, 'neben');

    expect(app(PairingService::class)->gpAnkerAlle($this->gp->id))->toHaveCount(3);

    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)->call('gpAnkerLoesen', $a2);

    expect(app(PairingService::class)->gpAnkerAlle($this->gp->id))->toHaveCount(2)
        ->and(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $this->gp->id)->where('anchor_id', $a2)->value('deleted_at'))->not->toBeNull();
});

it('GP-Modal: bei 3 Kern-Ankern lässt sich ein Nebenanker verknüpfen; 4. Kern und 7. Neben melden die Grenze der Rolle', function () {
    $svc = app(PairingService::class);
    foreach (['Brokkoli gekocht', 'Currypulver', 'Tofu'] as $n) {
        $svc->setGpAnker($this->rootTeam, $this->gp->id, ($this->neuAnker)($n), 'kern');
    }
    $kokos = ($this->neuAnker)('Kokos');

    $c = Livewire::test(GpModal::class)->call('oeffnen', $this->gp->id)
        ->set('gpAnkerRolle', 'neben')->call('gpAnkerVerknuepfen', $kokos)
        ->assertSet('gpAnkerFehler', null);
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $this->gp->id)->where('anchor_id', $kokos)->value('role'))->toBe('neben');

    $c->set('gpAnkerRolle', 'kern')->call('gpAnkerVerknuepfen', ($this->neuAnker)('Vierter Kern'))
        ->assertSet('gpAnkerFehler', 'Limit erreicht: max 3 Kern-Anker pro GP.');

    foreach (range(2, 6) as $i) {
        $svc->setGpAnker($this->rootTeam, $this->gp->id, ($this->neuAnker)("Neben {$i}"), 'neben');
    }
    $c->set('gpAnkerRolle', 'neben')->call('gpAnkerVerknuepfen', ($this->neuAnker)('Siebter Neben'))
        ->assertSet('gpAnkerFehler', 'Limit erreicht: max 6 Nebenanker pro GP.');
    expect(\Platform\FoodAlchemist\Support\AnkerGrenze::anzahl($this->gp->id, 'kern'))->toBe(3)->and(\Platform\FoodAlchemist\Support\AnkerGrenze::anzahl($this->gp->id, 'neben'))->toBe(6);
});

it('GP-Detail-Panel: Chip zeigt den Anker, Klick zeigt weitere GPs mit demselben Anker (kein Graph)', function () {
    $anker = ($this->neuAnker)('Rosmarin-Test');
    $anderesGp = $this->makeGp($this->rootTeam, 'Rosmarin-Zweitgp');
    app(PairingService::class)->setGpAnker($this->rootTeam, $this->gp->id, $anker, 'kern');
    app(PairingService::class)->setGpAnker($this->rootTeam, $anderesGp->id, $anker, 'kern');

    $c = Livewire::test(DetailPanel::class)->call('zeige', $this->gp->id);
    expect($c->html())->toContain('data-gp-anker-chip')->toContain('Rosmarin-Test');

    $c->call('ankerNetzUmschalten', $anker);
    expect($c->html())->toContain('data-gp-anker-netz')->toContain('Rosmarin-Zweitgp');
});

it('KI-Inferenz hält die Grenzen: kein vierter Kern-Anker neben 3 Katalog-Ankern; neben ergänzt, ohne den KI-Kern zu verdrängen', function () {
    $svc = app(PairingService::class);
    foreach (['Katalog 1', 'Katalog 2', 'Katalog 3'] as $n) {
        $svc->setGpAnker($this->rootTeam, $this->gp->id, ($this->neuAnker)($n), 'kern', 'inspire_katalog');
    }
    expect(fn () => $svc->setGpAnkerInference($this->rootTeam, $this->gp->id, ($this->neuAnker)('KI vier'), 0.8))
        ->toThrow(\RuntimeException::class, 'max 3 Kern-Anker');
    expect(\Platform\FoodAlchemist\Support\AnkerGrenze::anzahl($this->gp->id, 'kern'))->toBe(3);

    $gp2 = $this->makeGp($this->rootTeam, 'KI-Kern-Test');
    $kiKern = ($this->neuAnker)('KI Kern');
    $svc->setGpAnkerInference($this->rootTeam, $gp2->id, $kiKern, 0.8);
    expect($svc->setGpAnkerInference($this->rootTeam, $gp2->id, ($this->neuAnker)('KI Neben'), 0.7, 'x', \Platform\FoodAlchemist\Support\AnkerQuelle::KI, 'neben'))->toBeTrue();
    expect(\Platform\FoodAlchemist\Support\AnkerGrenze::anzahl($gp2->id, 'kern'))->toBe(1)->and(\Platform\FoodAlchemist\Support\AnkerGrenze::anzahl($gp2->id, 'neben'))->toBe(1)
        ->and(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp2->id)->where('anchor_id', $kiKern)->value('deleted_at'))->toBeNull();
});
