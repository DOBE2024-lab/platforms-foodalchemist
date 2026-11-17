<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 53 Paket J — MCP gp_anchors.PUT/IMPORT/GET: mehrere Aroma-Anker je GP (role kern|neben),
 * Slug-basiert (stabiler als die numerische Id für die Vault-Brücke alt→neu).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $name, array $a) => $this->registry->get($name)->execute($a, $this->kontext);
    $this->runChild = fn (string $name, array $a) => $this->registry->get($name)->execute($a, $this->childKontext);
    $this->neuGp = fn (string $hz) => $this->registry->get('foodalchemist.gps.POST')->execute(['hauptzutat' => $hz], $this->kontext)->data['id'];
    $this->neuAnker = fn (string $name) => DB::table('foodalchemist_vocab_pairing_anchors')->insertGetId([
        'uuid' => (string) Str::uuid(), 'team_id' => null,
        'slug' => Str::slug($name).'-'.bin2hex(random_bytes(3)), 'display_de' => $name,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->slugFuer = fn (int $ankerId) => DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $ankerId)->value('slug');
});

it('Registry-Smoke: gp_anchors-Tools registriert', function () {
    foreach (['foodalchemist.gp_anchors.PUT', 'foodalchemist.gp_anchors.IMPORT', 'foodalchemist.gp_anchors.GET'] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('gp_anchors.PUT: set (Default kern) + remove; unbekannter gp_id/slug → NOT_FOUND', function () {
    $gpId = ($this->neuGp)('Zander');
    $slug = ($this->slugFuer)(($this->neuAnker)('Zitrone-PUT'));

    $set = ($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => $slug]);
    expect($set->success)->toBeTrue()->and($set->data['role'])->toBe('kern');

    $remove = ($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => $slug, 'remove' => true]);
    expect($remove->success)->toBeTrue()->and($remove->data['action'])->toBe('remove');

    expect(($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => 999999, 'anchor_slug' => $slug])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => 'quatsch-slug-xyz'])->errorCode)->toBe('NOT_FOUND');
});

it('gp_anchors.PUT: Kern höchstens 3, Neben getrennt höchstens 6 → VALIDATION_ERROR je Rolle; Rollenwechsel nur mit Platz', function () {
    $gpId = ($this->neuGp)('Ratatouille-Gp');
    $kern = collect(range(1, 4))->map(fn ($i) => ($this->slugFuer)(($this->neuAnker)("Kern-Cap-{$i}")));
    $neben = collect(range(1, 7))->map(fn ($i) => ($this->slugFuer)(($this->neuAnker)("Neben-Cap-{$i}")));
    $put = fn (string $slug, string $role) => ($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => $slug, 'role' => $role]);

    foreach ($kern->take(3) as $s) {
        expect($put($s, 'kern')->success)->toBeTrue();
    }
    foreach ($neben->take(6) as $s) {
        expect($put($s, 'neben')->success)->toBeTrue();              // volle Kern-Grenze blockiert Nebenanker nicht mehr (Curry: Brokkoli)
    }
    $vierterKern = $put($kern[3], 'kern');
    expect($vierterKern->success)->toBeFalse()->and($vierterKern->errorCode)->toBe('VALIDATION_ERROR')
        ->and($vierterKern->error)->toContain('max 3 Kern-Anker');
    $siebterNeben = $put($neben[6], 'neben');
    expect($siebterNeben->success)->toBeFalse()->and($siebterNeben->errorCode)->toBe('VALIDATION_ERROR')
        ->and($siebterNeben->error)->toContain('max 6 Nebenanker');

    // Rollenwechsel neben → kern bei voller Kern-Grenze: abgelehnt, die Zeile bleibt Neben
    $wechsel = $put($neben[0], 'kern');
    expect($wechsel->success)->toBeFalse()->and($wechsel->error)->toContain('max 3 Kern-Anker');
    $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $neben[0])->value('id');
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->where('anchor_id', $ankerId)->value('role'))->toBe('neben');

    // nach dem Lösen eines Kern-Ankers geht der Wechsel; dieselbe Zeile zählt nicht gegen sich selbst
    expect(($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => $kern[0], 'remove' => true])->success)->toBeTrue();
    expect($put($neben[0], 'kern')->success)->toBeTrue()
        ->and($put($neben[0], 'kern')->success)->toBeTrue()
        ->and(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->where('anchor_id', $ankerId)->value('role'))->toBe('kern');
});

it('gp_anchors.GET: gp_id liefert kern+neben; anchor_slug liefert Reverse-Lookup', function () {
    $gpA = ($this->neuGp)('Anker-Reverse-Eins');
    $gpB = ($this->neuGp)('Anker-Reverse-Zwei');
    $ankerId = ($this->neuAnker)('Rosmarin-Get');
    $slug = ($this->slugFuer)($ankerId);
    ($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpA, 'anchor_slug' => $slug, 'role' => 'kern']);
    ($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpB, 'anchor_slug' => $slug, 'role' => 'kern']);

    $perGp = ($this->run)('foodalchemist.gp_anchors.GET', ['gp_id' => $gpA]);
    expect($perGp->success)->toBeTrue()
        ->and(collect($perGp->data['anker'])->pluck('anchor_slug')->all())->toBe([$slug]);

    $reverse = ($this->run)('foodalchemist.gp_anchors.GET', ['anchor_slug' => $slug]);
    expect($reverse->success)->toBeTrue()
        ->and(collect($reverse->data['gps'])->pluck('gp_id')->sort()->values()->all())->toBe(collect([$gpA, $gpB])->sort()->values()->all());
});

it('gp_anchors.GET ohne Argumente: vollständiger seitenweiser Export', function () {
    $gpId = ($this->neuGp)('Export-Gp');
    $slug = ($this->slugFuer)(($this->neuAnker)('Export-Anker'));
    ($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => $slug]);

    $export = ($this->run)('foodalchemist.gp_anchors.GET', []);
    expect($export->success)->toBeTrue()
        ->and($export->data['total'])->toBeGreaterThanOrEqual(1)
        ->and(collect($export->data['zuordnungen'])->pluck('anchor_slug'))->toContain($slug);
});

it('gp_anchors.IMPORT: idempotent (gleicher Eintrag zweimal → unveraendert), unbekannt → abgelehnt', function () {
    $gpId = ($this->neuGp)('Import-Gp');
    $slug = ($this->slugFuer)(($this->neuAnker)('Import-Anker'));

    $erster = ($this->run)('foodalchemist.gp_anchors.IMPORT', ['eintraege' => [
        ['gp_id' => $gpId, 'anchor_slug' => $slug, 'source' => 'bridge_alt_neu'],
    ]]);
    expect($erster->success)->toBeTrue()->and($erster->data['eintraege'][0]['status'])->toBe('angenommen');

    $zweiter = ($this->run)('foodalchemist.gp_anchors.IMPORT', ['eintraege' => [
        ['gp_id' => $gpId, 'anchor_slug' => $slug, 'source' => 'bridge_alt_neu'],
    ]]);
    expect($zweiter->data['eintraege'][0]['status'])->toBe('unveraendert');

    $unbekannt = ($this->run)('foodalchemist.gp_anchors.IMPORT', ['eintraege' => [
        ['gp_id' => 999999, 'anchor_slug' => $slug, 'source' => 'exact_name'],
        ['gp_id' => $gpId, 'anchor_slug' => 'kein-slug-xyz', 'source' => 'exact_name'],
    ]]);
    expect($unbekannt->data['eintraege'][0]['status'])->toBe('abgelehnt')
        ->and($unbekannt->data['eintraege'][1]['status'])->toBe('abgelehnt')
        ->and($unbekannt->data['abgelehnt'])->toBe(2);
});

it('gp_anchors.IMPORT: ersetze_alle loescht den Bestand einmal je gp_id im Batch, auch bei mehreren Eintraegen desselben GP', function () {
    $gpId = ($this->neuGp)('Ersetze-Gp');
    $alt = ($this->slugFuer)(($this->neuAnker)('Alt-Anker'));
    ($this->run)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => $alt]);

    $neu1 = ($this->slugFuer)(($this->neuAnker)('Neu-Anker-1'));
    $neu2 = ($this->slugFuer)(($this->neuAnker)('Neu-Anker-2'));
    $res = ($this->run)('foodalchemist.gp_anchors.IMPORT', ['eintraege' => [
        ['gp_id' => $gpId, 'anchor_slug' => $neu1, 'source' => 'bridge_alt_neu', 'ersetze_alle' => true],
        ['gp_id' => $gpId, 'anchor_slug' => $neu2, 'source' => 'bridge_alt_neu', 'ersetze_alle' => true],
    ]]);
    expect($res->data['angenommen'])->toBe(2);

    $bestand = ($this->run)('foodalchemist.gp_anchors.GET', ['gp_id' => $gpId]);
    $slugs = collect($bestand->data['anker'])->pluck('anchor_slug')->sort()->values()->all();
    expect($slugs)->toBe(collect([$neu1, $neu2])->sort()->values()->all())
        ->and($slugs)->not->toContain($alt);
});

it('gp_anchors.IMPORT: Kern-Grenze respektiert (vierter Kern-Eintrag desselben GP → abgelehnt mit Grund)', function () {
    $gpId = ($this->neuGp)('Cap-Import-Gp');
    $slugs = collect(range(1, 4))->map(fn ($i) => ($this->slugFuer)(($this->neuAnker)("Cap-Import-{$i}")));

    $res = ($this->run)('foodalchemist.gp_anchors.IMPORT', ['eintraege' => $slugs->map(fn ($s) => [
        'gp_id' => $gpId, 'anchor_slug' => $s, 'source' => 'bridge_alt_neu',
    ])->all()]);

    expect($res->data['angenommen'])->toBe(3)
        ->and($res->data['abgelehnt'])->toBe(1)
        ->and($res->data['eintraege'][3]['status'])->toBe('abgelehnt')
        ->and($res->data['eintraege'][3]['grund'])->toContain('Limit erreicht')
        ->and($res->data['eintraege'][3]['grund'])->toContain('Kern-Anker');
});

it('gp_anchors.IMPORT: Nebenanker getrennt gezählt — 3 Kern + 6 Neben angenommen, 7. Neben abgelehnt', function () {
    $gpId = ($this->neuGp)('Cap-Import-Neben-Gp');
    $eintraege = [];
    foreach (range(1, 3) as $i) {
        $eintraege[] = ['gp_id' => $gpId, 'anchor_slug' => ($this->slugFuer)(($this->neuAnker)("Imp-Kern-{$i}")), 'role' => 'kern', 'source' => 'bridge_alt_neu'];
    }
    foreach (range(1, 7) as $i) {
        $eintraege[] = ['gp_id' => $gpId, 'anchor_slug' => ($this->slugFuer)(($this->neuAnker)("Imp-Neben-{$i}")), 'role' => 'neben', 'source' => 'bridge_alt_neu'];
    }

    $res = ($this->run)('foodalchemist.gp_anchors.IMPORT', ['eintraege' => $eintraege]);

    expect($res->data['angenommen'])->toBe(9)
        ->and($res->data['abgelehnt'])->toBe(1)
        ->and($res->data['eintraege'][9]['grund'])->toContain('max 6 Nebenanker');
});

it('gp_anchors.PUT/IMPORT: Beschreibung nennt beide Grenzen', function () {
    foreach (['foodalchemist.gp_anchors.PUT', 'foodalchemist.gp_anchors.IMPORT'] as $name) {
        $d = $this->registry->get($name)->getDescription();
        expect($d)->toContain('3')->toContain('6')->not->toContain('CAP_GP', $name);
    }
});

it('gp_anchors.PUT: Kind-Team sieht das GP des Eltern-Teams (Ancestry-Sichtbarkeit) und kann den Anker setzen', function () {
    // Team-scoped auf ein SICHTBARES GP, keine Owner-Sperre — Anker-
    // Pflege ist ein geteiltes kuratorisches Anliegen, kein Struktur-Mapping (anders als gp_la.PUT
    // link/unlink, das isOwnedBy verlangt).
    $gpId = ($this->neuGp)('Eigenes-Gp');
    $slug = ($this->slugFuer)(($this->neuAnker)('Fremd-Test'));

    expect(($this->runChild)('foodalchemist.gp_anchors.PUT', ['gp_id' => $gpId, 'anchor_slug' => $slug])->success)->toBeTrue();
});
