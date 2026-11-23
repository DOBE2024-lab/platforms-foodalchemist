<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (S3): Rauchtest vor dem Zerlegen von SpeiseplanService. Jedes MCP-Tool, das SpeiseplanService nutzt, läuft einmal mit
 * gültigen Eingaben und liefert success — so fällt ein beim Schnitt verlorener Fassaden-Delegator sofort auf.
 * Kein Fachtest: Inhalte prüfen SpeiseplanServiceTest, McpSpeiseplanVervollstaendigungTest und die Spec-57-Tests.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Kantine');
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->svc = app(SpeiseplanService::class);
    // Plan (Entwurf, Montag 2027-01-04, 1-Wochen-Zyklus) mit den drei Starter-Linien und zwei Einträgen in der ersten Woche.
    $this->plan = $this->svc->create($this->rootTeam, ['name' => 'Rauch-Kantine', 'start_date' => '2027-01-04', 'cycle_weeks' => 1]);
    $this->linie = $this->plan->lines()->orderBy('sort_order')->first();
    $this->suppe = $this->makeRecipe($this->rootTeam, 'Rauch-Suppe', ['is_sales_recipe' => true]);
    $this->braten = $this->makeRecipe($this->rootTeam, 'Rauch-Braten', ['is_sales_recipe' => true]);
    $this->eintrag = $this->svc->addEintrag($this->rootTeam, $this->plan->id, [
        'entry_date' => '2027-01-04', 'mahlzeit' => 'mittag', 'line_id' => $this->linie->id, 'sales_recipe_id' => $this->suppe->id,
    ]);
    $this->eintrag2 = $this->svc->addEintrag($this->rootTeam, $this->plan->id, [
        'entry_date' => '2027-01-05', 'mahlzeit' => 'mittag', 'line_id' => $this->linie->id, 'sales_recipe_id' => $this->braten->id,
    ]);
    $this->rauch = function (string $tool, array $args) {
        $r = app($tool)->execute($args, $this->ctx);
        expect($r->success)->toBeTrue(class_basename($tool).': '.($r->error ?? json_encode($r->errorCode)));

        return $r;
    };
});

it('Speiseplan-Tools lesen: Liste, Detail, Einträge, Mengen-Matrix, Plan/Ist', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\SpeiseplaeneListTool::class, []);
    $r(Tools\SpeiseplaeneGetTool::class, ['id' => $this->plan->id]);
    $r(Tools\SpeiseplanEintraegeGetTool::class, ['plan_id' => $this->plan->id, 'von' => '2027-01-04', 'bis' => '2027-01-10', 'mahlzeit' => 'mittag']);
    $r(Tools\SpeiseplanMengenTool::class, ['plan_id' => $this->plan->id, 'montag' => '2027-01-04', 'mahlzeit' => 'mittag']);
    $r(Tools\SpeiseplanPlanIstTool::class, ['plan_id' => $this->plan->id, 'montag' => '2027-01-04', 'mahlzeit' => 'mittag']);
});

it('Speiseplan-Tools schreiben den Kopf: anlegen, ändern, Branding, Status, Kunde verknüpfen', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $neu = $r(Tools\SpeiseplaenePostTool::class, ['name' => 'Rauch-Neu', 'start_date' => '2027-02-01', 'cycle_weeks' => 2]);
    $neuId = (int) $neu->data['speiseplan']['id'];
    $r(Tools\SpeiseplaenePutTool::class, ['id' => $this->plan->id, 'felder' => ['default_pax' => 120, 'note' => 'Rauch']]);
    $r(Tools\SpeiseplanBrandingTool::class, ['id' => $this->plan->id, 'brand_color' => '#00ff88', 'band_color' => '#112233', 'footer_text' => 'Rauch-Fuß']);
    // CRM ist ein optionales Modul: ohne es wird die Verknüpfung gelöst (company_id weggelassen), mit ihm eine Firma des Haupt-Teams gesetzt.
    $kunde = ['id' => $this->plan->id];
    if (class_exists(\Platform\Crm\Models\CrmCompany::class)) {
        $kunde['company_id'] = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->id, 'name' => 'Rauch-Kunde', 'is_active' => true])->id;
    }
    $r(Tools\SpeiseplanCustomerLinkTool::class, $kunde);
    // Status auf dem NEUEN Plan, damit der Hauptplan Entwurf bleibt (Einträge sind via MCP nur im Entwurf editierbar).
    $r(Tools\SpeiseplaeneStatusTool::class, ['id' => $neuId, 'status' => 'aktiv']);
    expect($neu->data)->not->toBeEmpty();
});

it('Speiseplan-Tools pflegen Linien und Einträge: anlegen, ändern, verschieben, ersetzen, Pax', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $linie = $r(Tools\SpeiseplanLinienPostTool::class, ['plan_id' => $this->plan->id, 'name' => 'Rauch-Wok', 'is_vegetarian' => true, 'role' => 'hauptgang', 'meal' => 'mittag']);
    $linieId = (int) $linie->data['linie_id'];
    $r(Tools\SpeiseplanLinienPutTool::class, ['linie_id' => $linieId, 'felder' => ['name' => 'Rauch-Wok 2', 'default_pax' => 40]]);
    $r(Tools\SpeiseplanLinienMoveTool::class, ['linie_id' => $linieId, 'direction' => 'up']);

    $neu = $r(Tools\SpeiseplanEintraegePostTool::class, [
        'menu_plan_id' => $this->plan->id, 'entry_date' => '2027-01-06', 'mahlzeit' => 'mittag', 'line_id' => $linieId, 'sales_recipe_id' => $this->suppe->id,
    ]);
    $neuId = (int) $neu->data['eintrag']['id'];
    // Inhalt ersetzen (Gericht tauschen) und separat Zelle/Pax/Wording ändern — beides läuft über SpeiseplanEintraegePutTool.
    $r(Tools\SpeiseplanEintraegePutTool::class, ['eintrag_id' => $neuId, 'sales_recipe_id' => $this->braten->id]);
    $r(Tools\SpeiseplanEintraegePutTool::class, ['eintrag_id' => $neuId, 'entry_date' => '2027-01-07', 'line_id' => $linieId, 'pax' => 60, 'wording' => 'Rauch-Braten fein']);
    $r(Tools\SpeiseplanEintraegePaxTool::class, ['eintrag_id' => $this->eintrag->id, 'pax' => 90]);
});

it('Speiseplan-Tools Mengen und Bedarf: Zellen-Pax, Vorwoche, Skalieren, Wochenbedarf', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\SpeiseplanMengenPutTool::class, [
        'plan_id' => $this->plan->id, 'mahlzeit' => 'mittag', 'zellen' => [['line_id' => $this->linie->id, 'datum' => '2027-01-04', 'pax' => 80]],
    ]);
    $r(Tools\SpeiseplanMengenPutTool::class, ['plan_id' => $this->plan->id, 'mahlzeit' => 'mittag', 'aktion' => 'vorwoche', 'montag' => '2027-01-11']);
    $r(Tools\SpeiseplanMengenPutTool::class, ['plan_id' => $this->plan->id, 'mahlzeit' => 'mittag', 'aktion' => 'skalieren', 'montag' => '2027-01-04', 'faktor' => 1.5]);
    $r(Tools\SpeiseplanBedarfTool::class, ['plan_id' => $this->plan->id, 'montag' => '2027-01-04', 'mahlzeit' => 'mittag']);
    $r(Tools\SpeiseplanBedarfTool::class, ['plan_id' => $this->plan->id, 'montag' => '2027-01-04', 'tag' => '2027-01-04', 'mahlzeit' => 'mittag']);
});

it('Speiseplan-Tools Woche kopieren, Ausrollen und Anproduktion (jeweils confirm=true)', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\SpeiseplanWocheKopierenTool::class, [
        'plan_id' => $this->plan->id, 'von_montag' => '2027-01-04', 'nach_montag' => '2027-01-11', 'mahlzeit' => 'mittag', 'mit_pax' => true, 'confirm' => true,
    ]);
    $r(Tools\SpeiseplanAusrollenTool::class, ['plan_id' => $this->plan->id, 'bis_datum' => '2027-02-01', 'confirm' => true]);
    $r(Tools\SpeiseplanAnproduktionTool::class, ['plan_id' => $this->plan->id, 'mahlzeit' => 'mittag', 'montag' => '2027-01-04', 'confirm' => true]);
});

it('Speiseplan-Tools Vorlage für Betriebe: setzen, Kopie, Liste, Abgleich anzeigen und übernehmen', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $outlet = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Rauch-Werk']);
    $r(Tools\SpeiseplanVorlageTool::class, ['plan_id' => $this->plan->id, 'aktion' => 'setzen', 'ist_vorlage' => true]);
    $kopie = $r(Tools\SpeiseplanVorlageTool::class, ['plan_id' => $this->plan->id, 'aktion' => 'kopie', 'outlet_id' => $outlet->id]);
    $kopieId = (int) $kopie->data['kopie']['id'];
    $r(Tools\SpeiseplanVorlageTool::class, ['plan_id' => $this->plan->id, 'aktion' => 'liste']);
    $r(Tools\SpeiseplanVorlageAbgleichTool::class, ['kopie_id' => $kopieId, 'aktion' => 'anzeigen']);
    $r(Tools\SpeiseplanVorlageAbgleichTool::class, ['kopie_id' => $kopieId, 'aktion' => 'uebernehmen', 'confirm' => true]);
});

it('Speiseplan-Tools löschen zuletzt: Eintrag, Linie', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $linie = $r(Tools\SpeiseplanLinienPostTool::class, ['plan_id' => $this->plan->id, 'name' => 'Rauch-Weg']);
    $r(Tools\SpeiseplanEintraegeDeleteTool::class, ['eintrag_id' => $this->eintrag2->id, 'confirm' => true]);
    $r(Tools\SpeiseplanLinienDeleteTool::class, ['linie_id' => (int) $linie->data['linie_id'], 'confirm' => true]);
    expect(FoodAlchemistSpeiseplanEintrag::find($this->eintrag2->id))->toBeNull();
});

it('Alle Tools, die SpeiseplanService nutzen, sind hier abgedeckt', function () {
    $abgedeckt = collect(file(__FILE__))->flatMap(fn ($z) => preg_match_all('/Tools\\\\(\w+Tool)::class/', $z, $m) ? $m[1] : [])->unique()->sort()->values()->all();
    $nutzen = collect(glob(dirname(__DIR__, 2).'/src/Tools/*Tool.php'))
        ->filter(fn ($f) => preg_match('/\bSpeiseplanService\b/', (string) file_get_contents($f)))
        ->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();

    expect(array_values(array_diff($nutzen, $abgedeckt)))->toBe([]);
});
