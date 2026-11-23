<?php

use Illuminate\Support\Carbon;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistComponentEquivalent;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ComponentEquivalentService;
use Platform\FoodAlchemist\Services\Gericht\GerichtTauschService;
use Platform\FoodAlchemist\Services\Gericht\GerichtVerwendungen;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gericht-Verwaltung (Plan „Verwaltung am Gericht“, Kurator/Dominique 23.11.2026): „überall ersetzen, danach einwandfrei
 * löschen“. Sechs Tausch-Bereiche (Konzepte/Pakete, Komponenten, Speiseplan + Speisekarte, offene Angebote, Foodbooks,
 * Ersatz-Verknüpfungen), Vorschau ohne Schreiben, Löschsperre nur für aktive Verwendungen.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-23 10:00:00');
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->t = $this->rootTeam;
    $this->svc = app(GerichtTauschService::class);
    $this->alt = $this->makeRecipe($this->t, 'Rinderroulade', ['is_sales_recipe' => true]);
    $this->neu = $this->makeRecipe($this->t, 'Kalbsbäckchen', ['is_sales_recipe' => true]);
    $this->altStd = $this->makeStandardDarreichung($this->alt, 18.0);
    $this->neuStd = $this->makeStandardDarreichung($this->neu, 21.0);
    $this->buffet = FoodAlchemistServierform::create(['team_id' => $this->t->id, 'code' => 'buffet', 'label' => 'Buffet']);
    $this->altBuffet = $this->makeStandardDarreichung($this->alt, 12.0, ['serving_form_id' => $this->buffet->id, 'is_standard' => false]);
    $this->neuBuffet = $this->makeStandardDarreichung($this->neu, 14.0, ['serving_form_id' => $this->buffet->id, 'is_standard' => false]);
});

afterEach(fn () => Carbon::setTestNow());

function gtKarte($test, string $name, ?string $bis): FoodAlchemistSpeisekartePosition
{
    $karte = FoodAlchemistSpeisekarte::create(['team_id' => $test->t->id, 'name' => $name, 'gueltig_bis' => $bis]);
    $rubrik = FoodAlchemistSpeisekarteRubrik::create(['team_id' => $test->t->id, 'menu_card_id' => $karte->id, 'title' => 'Hauptgang', 'position' => 0]);

    return FoodAlchemistSpeisekartePosition::create(['team_id' => $test->t->id, 'section_id' => $rubrik->id, 'type' => 'gericht_ref',
        'sales_recipe_id' => $test->alt->id, 'position' => 0, 'wording' => 'Roulade nach Art des Hauses']);
}

function gtAngebot($test, string $name, string $status, string $preisModus = 'auto'): FoodAlchemistOfferBlock
{
    $a = app(AngebotService::class)->create($test->t, ['name' => $name]);
    FoodAlchemistAngebot::whereKey($a->id)->update(['status' => $status, 'price_mode' => $preisModus]);
    $kap = FoodAlchemistOfferChapter::create(['team_id' => $test->t->id, 'offer_id' => $a->id, 'title' => 'Hauptgang', 'position' => 0]);

    return FoodAlchemistOfferBlock::create(['team_id' => $test->t->id, 'chapter_id' => $kap->id, 'type' => 'recipe_ref', 'position' => 0,
        'visible' => true, 'sales_recipe_id' => $test->alt->id, 'quantity' => 1]);
}

it('Konzept-Position: Menge und Einheit bleiben, Darreichung mit gleicher Form, Wording geleert', function () {
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $slot = $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->alt->id, 'quantity' => 2, 'presentation_id' => $this->altBuffet->id]);

    $e = $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id);

    $slot->refresh();
    expect($e['getauscht']['konzepte'])->toBe(1)
        ->and((int) $slot->sales_recipe_id)->toBe($this->neu->id)
        ->and((float) $slot->quantity)->toBe(2.0)
        ->and((int) $slot->presentation_id)->toBe($this->neuBuffet->id)
        ->and($slot->wording)->toBeNull();
});

it('Komponente: Eltern-Zeile zeigt aufs neue Gericht', function () {
    $menue = $this->makeRecipe($this->t, 'Festmenü', ['is_sales_recipe' => true]);
    $zeile = $this->makeIngredient($menue, 'Roulade', null, '1');
    $zeile->update(['referenced_recipe_id' => $this->alt->id]);

    $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id, ['komponenten']);

    expect((int) $zeile->fresh()->referenced_recipe_id)->toBe($this->neu->id);
});

it('Speiseplan: ab heute getauscht, vergangene Tage bleiben', function () {
    $sp = app(SpeiseplanService::class);
    $plan = $sp->create($this->t, ['name' => 'Kantine', 'start_date' => '2026-11-16']);
    $gestern = $sp->addEintrag($this->t, $plan->id, ['entry_date' => '2026-11-20', 'sales_recipe_id' => $this->alt->id]);
    $morgen = $sp->addEintrag($this->t, $plan->id, ['entry_date' => '2026-11-24', 'sales_recipe_id' => $this->alt->id]);

    $e = $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id);

    expect($e['getauscht']['speiseplan'])->toBe(1)
        ->and((int) FoodAlchemistSpeiseplanEintrag::find($morgen->id)->sales_recipe_id)->toBe($this->neu->id)
        ->and((int) FoodAlchemistSpeiseplanEintrag::find($gestern->id)->sales_recipe_id)->toBe($this->alt->id);
});

it('Speisekarte: gültige Karte getauscht (Wording zurück), abgelaufene bleibt', function () {
    $gilt = gtKarte($this, 'Herbstkarte', null);
    $alt = gtKarte($this, 'Sommerkarte', '2026-09-30');

    $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id);

    expect((int) $gilt->fresh()->sales_recipe_id)->toBe($this->neu->id)
        ->and($gilt->fresh()->wording)->toBeNull()
        ->and((int) $alt->fresh()->sales_recipe_id)->toBe($this->alt->id);
});

it('Angebote: offene getauscht (manueller Preis bleibt, Hinweis), versendete historisch, eingefrorene bleiben', function () {
    $offen = gtAngebot($this, 'Hochzeit', 'anfrage');
    $versendet = gtAngebot($this, 'Firmenfeier', 'versendet');
    $manuell = gtAngebot($this, 'Jubiläum', 'in_arbeit', 'manual');
    $manuellId = $manuell->chapter->offer_id;
    FoodAlchemistAngebot::whereKey($manuellId)->update(['total_price' => 999.0]);
    $eingefroren = gtAngebot($this, 'Gala', 'angebot');
    \Platform\FoodAlchemist\Models\FoodAlchemistAngebotEinfrierung::create(['team_id' => $this->t->id, 'angebot_id' => $eingefroren->chapter->offer_id, 'status_beim_einfrieren' => 'angebot', 'eingefroren_at' => now()]);

    $v = $this->svc->vorschau($this->t, $this->alt->id, $this->neu->id);
    expect(collect($v['bereiche']['angebote']['eintraege'])->firstWhere('ort', 'Angebot „Jubiläum“')['hinweis'])->toBe('Preis manuell – bitte prüfen')
        ->and(collect($v['bereiche']['angebote']['preise'])->firstWhere('name', 'Jubiläum')['preis_manuell'])->toBeTrue();

    $e = $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id);

    expect((int) $offen->fresh()->sales_recipe_id)->toBe($this->neu->id)
        ->and((int) $manuell->fresh()->sales_recipe_id)->toBe($this->neu->id)
        ->and((float) FoodAlchemistAngebot::find($manuellId)->total_price)->toBe(999.0)   // manueller Preis bleibt
        ->and((int) $versendet->fresh()->sales_recipe_id)->toBe($this->alt->id)
        ->and((int) $eingefroren->fresh()->sales_recipe_id)->toBe($this->alt->id)
        ->and(collect($e['bleibt'])->pluck('grund')->all())->toContain('Angebot eingefroren (Stand der Abo-Rezepte)')
        ->and(app(GerichtVerwendungen::class)->loeschBilanz($this->t, $this->alt->id)['aktiv'])->toHaveCount(1);   // nur das eingefrorene sperrt
});

it('Foodbooks: Entwurf getauscht, aktives nur mit Haken, inaktives historisch', function () {
    $mk = fn (string $label, string $status) => $this->makeFoodbookBlock($this->makeChapter($this->makeFoodbook($this->t, $label, ['status' => $status])),
        ['sales_recipe_id' => $this->alt->id]);
    $entwurf = $mk('Entwurf 2027', 'entwurf');
    $aktiv = $mk('Foodbook 2026', 'aktiv');
    $inaktiv = $mk('Foodbook 2024', 'inaktiv');

    $ohne = $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id, ['foodbooks']);
    expect((int) $entwurf->fresh()->sales_recipe_id)->toBe($this->neu->id)
        ->and((int) $aktiv->fresh()->sales_recipe_id)->toBe($this->alt->id)
        ->and($ohne['getauscht']['foodbooks'])->toBe(1);

    $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id, ['foodbooks'], auchAktiveFoodbooks: true);
    expect((int) $aktiv->fresh()->sales_recipe_id)->toBe($this->neu->id)
        ->and((int) $inaktiv->fresh()->sales_recipe_id)->toBe($this->alt->id);
});

it('Ersatz-Verknüpfung zieht aufs neue Gericht um', function () {
    $basis = $this->makeRecipe($this->t, 'Schmorgericht vegetarisch', ['is_sales_recipe' => true]);
    app(ComponentEquivalentService::class)->verknuepfe($this->t, 'recipe', $this->alt->id, 'recipe', $basis->id, 1.5);

    $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id, ['ersatz']);

    $equiv = FoodAlchemistComponentEquivalent::where('alt_id', $basis->id)->get();
    expect($equiv)->toHaveCount(1)
        ->and((int) $equiv->first()->source_id)->toBe($this->neu->id)
        ->and((float) $equiv->first()->umrechnungsfaktor)->toBe(1.5);
});

it('Vorschau schreibt nichts und nennt Angebotspreis vorher und nachher wie nach dem Tausch', function () {
    $offen = gtAngebot($this, 'Hochzeit', 'anfrage');
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $slot = $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->alt->id]);

    $v = $this->svc->vorschau($this->t, $this->alt->id, $this->neu->id);

    expect((int) $offen->fresh()->sales_recipe_id)->toBe($this->alt->id)
        ->and((int) $slot->fresh()->sales_recipe_id)->toBe($this->alt->id)
        ->and($v['bereiche']['konzepte']['anzahl'])->toBe(1)
        ->and($v['bereiche']['angebote']['anzahl'])->toBe(1);
    $preis = collect($v['bereiche']['angebote']['preise'])->firstWhere('name', 'Hochzeit');
    expect($preis)->not->toBeNull();

    $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id);
    $kalk = app(AngebotService::class)->kalkulation($this->t, FoodAlchemistAngebot::find($offen->chapter->offer_id));
    expect($preis['preis_nachher'])->toBe((float) $kalk['auto_gesamt']);
});

it('alles oder nichts: wirft ein Bereich, bleibt auch der Rest unverändert', function () {
    $konzept = $this->makeConcept($this->t, 'Herbstmenü');
    $slot = $this->makeConceptSlot($konzept, ['sales_recipe_id' => $this->alt->id]);
    gtKarte($this, 'Herbstkarte', null);
    app()->instance(SpeisekarteService::class, new class extends SpeisekarteService
    {
        public function __construct() {}

        public function updatePosition(\Platform\Core\Models\Team $team, int $positionId, array $in): FoodAlchemistSpeisekartePosition
        {
            throw new RuntimeException('kaputt');
        }
    });

    expect(fn () => $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id))->toThrow(RuntimeException::class, 'kaputt');
    expect((int) $slot->fresh()->sales_recipe_id)->toBe($this->alt->id);
});

it('fremdes Team: bleibt unangetastet und sperrt das Löschen mit Teamname', function () {
    $fremd = $this->makeConcept($this->childA, 'Menü Kind-Team');
    $slot = $this->makeConceptSlot($fremd, ['sales_recipe_id' => $this->alt->id]);

    $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id);
    $bilanz = app(GerichtVerwendungen::class)->loeschBilanz($this->t, $this->alt->id);

    expect((int) $slot->fresh()->sales_recipe_id)->toBe($this->alt->id)
        ->and($bilanz['frei'])->toBeFalse()
        ->and($bilanz['aktiv'][0]['grund'])->toContain('aktiv genutzt');
    expect(fn () => app(SalesRecipeService::class)->deleteDish($this->t, $this->alt->id))->toThrow(RuntimeException::class, 'Menü Kind-Team');
});

it('Abnahme: Tausch aller Bereiche mit Haken → deleteDish geht durch → historische Verweise zeigen den Namen', function () {
    $sp = app(SpeiseplanService::class);
    $plan = $sp->create($this->t, ['name' => 'Kantine', 'start_date' => '2026-11-16']);
    $gestern = $sp->addEintrag($this->t, $plan->id, ['entry_date' => '2026-11-20', 'sales_recipe_id' => $this->alt->id]);
    $sp->addEintrag($this->t, $plan->id, ['entry_date' => '2026-11-24', 'sales_recipe_id' => $this->alt->id]);
    $this->makeConceptSlot($this->makeConcept($this->t, 'Herbstmenü'), ['sales_recipe_id' => $this->alt->id]);
    gtKarte($this, 'Herbstkarte', null);
    $abgelaufen = gtKarte($this, 'Sommerkarte', '2026-09-30');
    gtAngebot($this, 'Hochzeit', 'anfrage');
    $versendet = gtAngebot($this, 'Firmenfeier', 'versendet');
    $aktivesFb = $this->makeFoodbookBlock($this->makeChapter($this->makeFoodbook($this->t, 'Foodbook 2026')), ['sales_recipe_id' => $this->alt->id]);
    $archiv = $this->makeFoodbookBlock($this->makeChapter($this->makeFoodbook($this->t, 'Foodbook 2023', ['status' => 'archiviert'])), ['sales_recipe_id' => $this->alt->id]);

    expect(app(GerichtVerwendungen::class)->loeschBilanz($this->t, $this->alt->id)['frei'])->toBeFalse();
    $this->svc->ersetze($this->t, $this->alt->id, $this->neu->id, auchAktiveFoodbooks: true);
    $bilanz = app(GerichtVerwendungen::class)->loeschBilanz($this->t, $this->alt->id);
    expect($bilanz['frei'])->toBeTrue()->and($bilanz['historisch'])->toBe(4)
        ->and((int) $aktivesFb->fresh()->sales_recipe_id)->toBe($this->neu->id);

    app(SalesRecipeService::class)->deleteDish($this->t, $this->alt->id);

    expect(FoodAlchemistRecipe::find($this->alt->id))->toBeNull()
        ->and(FoodAlchemistSpeiseplanEintrag::find($gestern->id)->inhaltName())->toBe('Rinderroulade')
        ->and($abgelaufen->fresh()->dish->name)->toBe('Rinderroulade')
        ->and($versendet->fresh()->dish->name)->toBe('Rinderroulade')
        ->and($archiv->fresh()->dish->name)->toBe('Rinderroulade')
        ->and(FoodAlchemistConceptSlot::where('sales_recipe_id', $this->alt->id)->count())->toBe(0);
});
