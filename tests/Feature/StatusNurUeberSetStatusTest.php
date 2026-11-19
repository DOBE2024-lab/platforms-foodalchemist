<?php

use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 1.1j-6: Der Status von Angebot und Format läuft nie an setStatus vorbei
 * (Rolle Kuratieren, beim Angebot Einfrieren/Snapshot). Ein Betrachter kann ihn auch über das
 * allgemeine update nicht ändern; ein unveränderter Status im Formular stört nicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->angebot = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommerfest', 'status' => 'anfrage', 'personen' => 50]);
    $this->format = app(FormatService::class)->create($this->rootTeam, ['name' => 'Streetfood']);
});

it('Angebot: Betrachter ändert den Status nicht über update', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Gast', 'viewer'));

    expect(fn () => app(AngebotService::class)->update($this->rootTeam, $this->angebot->id, ['status' => 'in_arbeit']))
        ->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
    expect($this->angebot->fresh()->status->value)->toBe('anfrage');
});

it('Angebot: Mitglied ändert den Status über update — läuft durch setStatus; unveränderter Status stört andere Felder nicht', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Koch', 'member'));
    $svc = app(AngebotService::class);

    $svc->update($this->rootTeam, $this->angebot->id, ['status' => 'in_arbeit', 'name' => 'Sommerfest 2027']);
    $a = $this->angebot->fresh();
    expect($a->status->value)->toBe('in_arbeit')->and($a->name)->toBe('Sommerfest 2027');

    $svc->update($this->rootTeam, $this->angebot->id, ['status' => 'in_arbeit', 'personen' => 80]);
    expect($this->angebot->fresh()->personen)->toBe(80);
});

it('Format: Betrachter ändert den Status nicht über update, Mitglied schon', function () {
    $svc = app(FormatService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Gast', 'viewer'));
    expect(fn () => $svc->update($this->rootTeam, $this->format->id, ['status' => 'active']))->toThrow(\Platform\FoodAlchemist\Exceptions\FaRechtFehltException::class);
    expect($this->format->fresh()->status)->toBe('draft');

    $this->actingAs($this->makeUser($this->rootTeam, 'Koch', 'member'));
    $svc->update($this->rootTeam, $this->format->id, ['status' => 'active', 'claim' => 'Heiß & schnell']);
    expect($this->format->fresh()->status)->toBe('active')->and($this->format->fresh()->claim)->toBe('Heiß & schnell');
});

it('Format: Servierform eines fremden Teams wird abgelehnt', function () {
    $this->actingAs($this->makeUser($this->childA, 'Koch A', 'member'));
    $fremd = \Platform\FoodAlchemist\Models\FoodAlchemistServierform::create(['team_id' => $this->childB->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $f = app(FormatService::class)->create($this->childA, ['name' => 'Format A']);

    expect(fn () => app(FormatService::class)->update($this->childA, $f->id, ['serving_form_id' => $fremd->id]))
        ->toThrow(\RuntimeException::class, 'nicht sichtbar');
});
