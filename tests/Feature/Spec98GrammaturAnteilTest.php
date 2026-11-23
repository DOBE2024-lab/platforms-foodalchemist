<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Tests\Support\GrammaturWelt;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\RecipeDarreichungPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class, GrammaturWelt::class);

/**
 * Plan 98 B, Schritt 5: „alle Mengen auf … %“ — je Komponente δ_i = m_i_std × p. Ersetzt die freie Grammatur einer Variante,
 * die mit der Sperre (Schritt 4d) wegfällt.
 */
beforeEach(function () {
    $this->grammaturWeltAnlegen();
    $this->svc = app(DarreichungService::class);
    $this->dar = fn ($r, string $form) => FoodAlchemistRecipeDarreichung::where('recipe_id', $r->id)
        ->whereHas('servingForm', fn ($q) => $q->where('code', $form))->firstOrFail();
    $this->deltas = fn ($d) => $d->fresh()->deltas()->with('ingredient')->get()
        ->mapWithKeys(fn ($x) => [$x->ingredient->raw_text => [(float) $x->quantity_override_g, (bool) $x->omitted]])->sortKeys()->all();
    $this->put = fn (array $args) => app(RecipeDarreichungPutTool::class)->execute($args, new ToolContext(Auth::user(), $this->rootTeam));
});

it('Variante auf 50 %: jede Komponente halb so viel wie im Standard, Grammatur und EK folgen', function () {
    // A · Standard-Teller 300 g = 250 g Kartoffeln + 50 g Speck (Ansatz 1000 + 200, 1,20 €/kg und 9,00 €/kg)
    $kinder = ($this->dar)($this->a, 'kinder');
    $this->svc->setzeAnteil($this->rootTeam, $kinder->id, 50);

    $k = $kinder->fresh();
    expect(($this->deltas)($k))->toBe(['Kartoffeln' => [125.0, false], 'Speck' => [25.0, false]])
        ->and($this->svc->grammJeEinheit($k, $this->a->fresh()))->toBe(150.0)
        ->and((float) $k->ek_portion)->toBe(0.375)        // 125 g × 0,0012 + 25 g × 0,009
        ->and((bool) $k->menge_vom_standard)->toBeFalse();
});

it('ersetzt vorhandene Deltas der Variante; Standard aus den Komponenten als Bezug', function () {
    // B · Standard „wie Rezept“ 180 g Rind + 80 g Jus; Variante „klein“ hatte Rind 120 g
    $klein = ($this->dar)($this->b, 'klein');
    $this->svc->setzeAnteil($this->rootTeam, $klein->id, 50);

    expect(($this->deltas)($klein))->toBe(['Jus' => [40.0, false], 'Rinderoberschale' => [90.0, false]])
        ->and($this->svc->grammJeEinheit($klein->fresh(), $this->b->fresh()))->toBe(130.0);
});

it('Weglassungen der Variante bleiben: „ohne Speck“ auf 50 % bekommt keinen Speck zurück', function () {
    // A · „ohne Speck“ lässt Speck per Delta weg (Kurator 23.11.: sichere Variante bis zum Produktentscheid)
    $ohneSpeck = ($this->dar)($this->a, 'ohne-speck');
    $this->svc->setzeAnteil($this->rootTeam, $ohneSpeck->id, 50);

    expect(($this->deltas)($ohneSpeck))->toBe(['Kartoffeln' => [125.0, false], 'Speck' => [0.0, true]])
        ->and($this->svc->grammJeEinheit($ohneSpeck->fresh(), $this->a->fresh()))->toBe(125.0);

    // Auch eine auf 0 g gesetzte Komponente gilt als weggelassen
    $kinder = ($this->dar)($this->a, 'kinder');
    $speck = $this->a->ingredients()->where('raw_text', 'Speck')->firstOrFail();
    $this->svc->setzeDelta($this->rootTeam, $kinder->id, $speck->id, 0.0, false);
    $this->svc->setzeAnteil($this->rootTeam, $kinder->id, 50);
    expect(($this->deltas)($kinder)['Speck'])->toBe([0.0, true]);
});

it('nicht am Standard, nicht 0, nicht über 1000 %', function () {
    $standard = ($this->dar)($this->a, 'teller');
    $kinder = ($this->dar)($this->a, 'kinder');
    expect(fn () => $this->svc->setzeAnteil($this->rootTeam, $standard->id, 50))->toThrow(RuntimeException::class, 'Standard-Darreichung')
        ->and(fn () => $this->svc->setzeAnteil($this->rootTeam, $kinder->id, 0))->toThrow(RuntimeException::class)
        ->and(fn () => $this->svc->setzeAnteil($this->rootTeam, $kinder->id, 1001))->toThrow(RuntimeException::class);
    expect($kinder->fresh()->deltas()->count())->toBe(0);
});

it('MCP: anteil_prozent an recipe_darreichung.PUT, allein und neben attrs; Standard und Mengenfelder abgelehnt', function () {
    $kinder = ($this->dar)($this->a, 'kinder');
    $r = ($this->put)(['presentation_id' => $kinder->id, 'anteil_prozent' => 50]);
    expect($r->success)->toBeTrue('put: '.($r->error ?? ''))
        ->and($this->svc->grammJeEinheit($kinder->fresh(), $this->a->fresh()))->toBe(150.0);

    $r = ($this->put)(['presentation_id' => $kinder->id, 'attrs' => ['note' => 'Kinderteller'], 'anteil_prozent' => '40']);
    expect($r->success)->toBeTrue('put: '.($r->error ?? ''))
        ->and($kinder->fresh()->note)->toBe('Kinderteller')
        ->and($this->svc->grammJeEinheit($kinder->fresh(), $this->a->fresh()))->toBe(120.0);

    expect(($this->put)(['presentation_id' => ($this->dar)($this->a, 'teller')->id, 'anteil_prozent' => 50])->errorCode)->toBe('VALIDATION_ERROR')
        ->and(($this->put)(['presentation_id' => $kinder->id, 'attrs' => ['quantity_per_unit_g' => 99], 'anteil_prozent' => 50])->errorCode)->toBe('VALIDATION_ERROR')
        ->and(($this->put)(['presentation_id' => $kinder->id, 'anteil_prozent' => 'halb'])->errorCode)->toBe('VALIDATION_ERROR');
});

it('Gericht-Modal: Knopf an der Variante setzt den Anteil, Komma erlaubt; ungültige Eingabe meldet einen Fehler', function () {
    $kinder = ($this->dar)($this->a, 'kinder');
    $lw = Livewire::test(VkModal::class)->call('oeffnen', $this->a->id);
    $lw->set("darAnteilForm.{$kinder->id}", 'x')->call('darAnteil', $kinder->id);
    expect($lw->get('fehler'))->not->toBeNull()->and($kinder->fresh()->deltas()->count())->toBe(0);

    $lw->set("darAnteilForm.{$kinder->id}", '33,5')->call('darAnteil', $kinder->id)->assertSet('fehler', null);
    expect(($this->deltas)($kinder))->toBe(['Kartoffeln' => [83.75, false], 'Speck' => [16.75, false]]);
});
