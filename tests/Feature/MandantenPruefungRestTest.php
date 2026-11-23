<?php

use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistTargetGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabRegenerationDevice;
use Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Code-Qualität 2.3f: Mandanten-Prüfungen, Rest aus der Inventur 1.1i (mittel/niedrig). Maßstab für Verweise ist die
 * Sicht-Regel (Support\Verweisziel), nie „eigenes Team“; Struktur-Verweise (Kapitel, Slot) bleiben im selben Dokument.
 * childA arbeitet, childB ist fremd (Geschwister, unsichtbar).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->childA, 'Koch A', 'member'));
    $this->fb = app(FoodbookService::class);
    $this->stil = fn ($team, $slug) => FoodAlchemistWritingStyle::create(['team_id' => $team->id, 'slug' => $slug, 'name' => $slug, 'sprach_duktus' => 'Kurz.', 'is_inactive' => false, 'sort_order' => 0]);
    $this->foodbookA = $this->fb->create($this->childA, ['label' => 'Gala A']);
    $this->kapitel = fn ($fbId, $titel = 'Vorspeisen') => FoodAlchemistFoodbookKapitel::create(['team_id' => $this->childA->id, 'foodbook_id' => $fbId, 'position' => 1, 'title' => $titel]);
});

it('Foodbook update: fremder Schreibstil wird abgelehnt, ein schon gespeicherter blockiert andere Felder nicht', function () {
    $fremd = ($this->stil)($this->childB, 'fremd');
    expect(fn () => $this->fb->update($this->childA, $this->foodbookA->id, ['writing_style_id' => $fremd->id]))->toThrow(RuntimeException::class, 'nicht sichtbar');

    $eigen = ($this->stil)($this->childA, 'eigen');
    $this->fb->update($this->childA, $this->foodbookA->id, ['writing_style_id' => $eigen->id]);
    expect($this->foodbookA->fresh()->writing_style_id)->toBe($eigen->id);

    $this->foodbookA->forceFill(['writing_style_id' => $fremd->id])->saveQuietly();   // Altbestand
    $this->fb->update($this->childA, $this->foodbookA->id, ['writing_style_id' => $fremd->id, 'description' => 'neu']);
    expect($this->foodbookA->fresh()->description)->toBe('neu');
});

it('Foodbook: Einsatzmoment/Zielgruppe — fremde ID nicht anhängbar, eine schon hängende lässt sich lösen', function () {
    $moment = FoodAlchemistEinsatzmoment::create(['team_id' => $this->childB->id, 'name' => 'Fremd', 'is_inactive' => false, 'sort_order' => 1]);
    $gruppe = FoodAlchemistTargetGroup::create(['team_id' => $this->childB->id, 'name' => 'Fremd-Gast']);

    expect(fn () => $this->fb->toggleEinsatzmoment($this->childA, $this->foodbookA->id, $moment->id))->toThrow(RuntimeException::class, 'nicht sichtbar')
        ->and(fn () => $this->fb->toggleZielgruppe($this->childA, $this->foodbookA->id, $gruppe->id))->toThrow(RuntimeException::class, 'nicht sichtbar');

    $this->foodbookA->serviceMoments()->attach($moment->id);
    $this->fb->toggleEinsatzmoment($this->childA, $this->foodbookA->id, $moment->id);
    expect($this->foodbookA->serviceMoments()->count())->toBe(0);
});

it('Foodbook: Kapitel-Zielgruppen nur sichtbares Vokabular', function () {
    $k = ($this->kapitel)($this->foodbookA->id);
    $fremd = FoodAlchemistTargetGroup::create(['team_id' => $this->childB->id, 'name' => 'Fremd-Gast']);
    $eigen = FoodAlchemistTargetGroup::create(['team_id' => $this->childA->id, 'name' => 'Gast']);

    expect(fn () => $this->fb->setKapitelZielgruppen($this->childA, $k->id, [$eigen->id, $fremd->id]))->toThrow(RuntimeException::class, 'nicht sichtbar');
    $this->fb->setKapitelZielgruppen($this->childA, $k->id, [$eigen->id]);
    expect($k->targetGroups()->allRelatedIds()->all())->toBe([$eigen->id]);
});

it('Foodbook-Kapitel: fremdes Einsatzmoment-/Servierform-Vokabular abgelehnt', function () {
    $k = ($this->kapitel)($this->foodbookA->id);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childB->id, 'code' => 'buffet', 'label' => 'Buffet']);

    expect(fn () => $this->fb->updateKapitel($this->childA, $k->id, ['serving_form_id' => $sf->id]))->toThrow(RuntimeException::class, 'nicht sichtbar');
});

it('Kapitel verschieben nur innerhalb desselben Foodbooks bzw. Angebots', function () {
    $k = ($this->kapitel)($this->foodbookA->id);
    $anderes = $this->fb->create($this->childA, ['label' => 'Gala B']);
    $fremdesKapitel = ($this->kapitel)($anderes->id, 'Hauptgang');
    expect(fn () => $this->fb->moveKapitel($this->childA, $k->id, $fremdesKapitel->id))->toThrow(RuntimeException::class, 'nicht zu diesem Foodbook');
    $ziel = ($this->kapitel)($this->foodbookA->id, 'Menü');
    $this->fb->moveKapitel($this->childA, $k->id, $ziel->id);
    expect($k->fresh()->parent_id)->toBe($ziel->id);

    $a1 = FoodAlchemistAngebot::create(['team_id' => $this->childA->id, 'name' => 'A1', 'status' => 'anfrage', 'personen' => 10]);
    $a2 = FoodAlchemistAngebot::create(['team_id' => $this->childA->id, 'name' => 'A2', 'status' => 'anfrage', 'personen' => 10]);
    $c1 = FoodAlchemistOfferChapter::create(['team_id' => $this->childA->id, 'offer_id' => $a1->id, 'title' => 'Menü', 'position' => 0]);
    $c2 = FoodAlchemistOfferChapter::create(['team_id' => $this->childA->id, 'offer_id' => $a2->id, 'title' => 'Menü', 'position' => 0]);
    expect(fn () => app(OfferCompositionService::class)->moveKapitel($this->childA, $c1->id, $c2->id))->toThrow(RuntimeException::class, 'nicht zu diesem Angebot');
});

it('Angebots-Kapitel: fremder Schreibstil abgelehnt', function () {
    $a = FoodAlchemistAngebot::create(['team_id' => $this->childA->id, 'name' => 'A1', 'status' => 'anfrage', 'personen' => 10]);
    $c = FoodAlchemistOfferChapter::create(['team_id' => $this->childA->id, 'offer_id' => $a->id, 'title' => 'Menü', 'position' => 0]);

    expect(fn () => app(OfferCompositionService::class)->updateKapitel($this->childA, $c->id, ['writing_style_id' => ($this->stil)($this->childB, 'x')->id]))
        ->toThrow(RuntimeException::class, 'nicht sichtbar');
});

it('uebernehmeGericht: Kapitel muss zum Foodbook gehören', function () {
    $anderes = $this->fb->create($this->childA, ['label' => 'Gala B']);
    $k = ($this->kapitel)($anderes->id);
    $gericht = FoodAlchemistRecipe::create(['team_id' => $this->childA->id, 'recipe_key' => 'g', 'name' => 'Gericht', 'status' => 'approved', 'is_sales_recipe' => true]);

    expect(fn () => $this->fb->uebernehmeGericht($this->childA, $this->foodbookA->id, $k->id, $gericht->id))->toThrow(RuntimeException::class, 'nicht zu diesem Foodbook');
});

it('Planungs-Gerüst: chapter_id nur aus dem Foodbook des Gerüsts', function () {
    $svc = app(PlanningFrameService::class);
    $frame = $svc->frameFor($this->childA, 'foodbook', $this->foodbookA->id);
    $anderes = $this->fb->create($this->childA, ['label' => 'Gala B']);
    $fremd = ($this->kapitel)($anderes->id);

    expect(fn () => $svc->addSlot($this->childA, $frame, ['label' => 'Vorspeisen', 'chapter_id' => $fremd->id]))->toThrow(RuntimeException::class, 'Kapitel gehört nicht');
    $slot = $svc->addSlot($this->childA, $frame, ['label' => 'Vorspeisen', 'chapter_id' => ($this->kapitel)($this->foodbookA->id)->id]);
    expect(fn () => $svc->updateSlot($this->childA, $slot->id, ['chapter_id' => $fremd->id]))->toThrow(RuntimeException::class, 'Kapitel gehört nicht');

    // Ein schon gespeicherter, inzwischen ungültiger Verweis blockiert das Speichern anderer Felder nicht (Livewire schickt chapter_id immer mit).
    $slot->forceFill(['chapter_id' => $fremd->id])->saveQuietly();
    $svc->updateSlot($this->childA, $slot->id, ['chapter_id' => $fremd->id, 'label' => 'Kalte Vorspeisen']);
    expect($slot->fresh()->label)->toBe('Kalte Vorspeisen');
});

it('Planungs-Gerüst am Angebot: chapter_id ist ein Kapitel DIESES Angebots — Foodbook-Kapitel und fremde Angebote abgelehnt', function () {
    $svc = app(PlanningFrameService::class);
    $a1 = FoodAlchemistAngebot::create(['team_id' => $this->childA->id, 'name' => 'A1', 'status' => 'anfrage', 'personen' => 10]);
    $a2 = FoodAlchemistAngebot::create(['team_id' => $this->childA->id, 'name' => 'A2', 'status' => 'anfrage', 'personen' => 10]);
    $eigen = FoodAlchemistOfferChapter::create(['team_id' => $this->childA->id, 'offer_id' => $a1->id, 'title' => 'Menü', 'position' => 0]);
    $fremd = FoodAlchemistOfferChapter::create(['team_id' => $this->childA->id, 'offer_id' => $a2->id, 'title' => 'Menü', 'position' => 0]);
    $frame = $svc->frameFor($this->childA, 'offer', $a1->id);
    // Foodbook- und Angebots-Kapitel sind getrennte Tabellen: eine Foodbook-Kapitel-ID, die NICHT zufällig gleich der eigenen Angebots-Kapitel-ID ist
    do {
        $fbKapitel = ($this->kapitel)($this->foodbookA->id);
    } while ($fbKapitel->id === $eigen->id);

    $slot =$svc->addSlot($this->childA, $frame, ['label' => 'Menü', 'chapter_id' => $eigen->id]);
    expect((int) $slot->chapter_id)->toBe($eigen->id)
        ->and(fn () => $svc->updateSlot($this->childA, $slot->id, ['chapter_id' => $fremd->id]))->toThrow(RuntimeException::class, 'Kapitel gehört nicht')
        ->and(fn () => $svc->addSlot($this->childA, $frame, ['label' => 'X', 'chapter_id' => $fbKapitel->id]))
        ->toThrow(RuntimeException::class, 'Kapitel gehört nicht');
});

it('Speisekarte: Verweise auch bei Text-Positionen geprüft, Darreichung muss zum Gericht gehören', function () {
    $svc = app(SpeisekarteService::class);
    $karte = $svc->create($this->childA, ['name' => 'Karte']);
    $rubrik = $svc->addRubrik($this->childA, $karte->id, ['title' => 'Hauptgänge']);
    $fremd = FoodAlchemistRecipe::create(['team_id' => $this->childB->id, 'recipe_key' => 'f', 'name' => 'Fremd', 'status' => 'approved', 'is_sales_recipe' => true]);
    expect(fn () => $svc->addPosition($this->childA, $rubrik->id, ['type' => 'text', 'label' => 'x', 'sales_recipe_id' => $fremd->id]))->toThrow(RuntimeException::class);

    $mk = fn ($key) => FoodAlchemistRecipe::create(['team_id' => $this->childA->id, 'recipe_key' => $key, 'name' => $key, 'status' => 'approved', 'is_sales_recipe' => true]);
    [$g1, $g2] = [$mk('g1'), $mk('g2')];
    $sf = FoodAlchemistServierform::create(['team_id' => $this->childA->id, 'code' => 'teller', 'label' => 'Teller']);
    $d2 = app(DarreichungService::class)->anlegen($this->childA, $g2->id, $sf->id, ['unit_count' => 1]);   // Plan 98 B: Grammatur spielt hier keine Rolle
    expect(fn () => $svc->addPosition($this->childA, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g1->id, 'presentation_id' => $d2->id]))
        ->toThrow(RuntimeException::class, 'Darreichung gehört nicht');
    $pos = $svc->addPosition($this->childA, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $g2->id, 'presentation_id' => $d2->id]);
    expect($pos->presentation_id)->toBe($d2->id);
});

it('Konzept-Kategorie/-Klasse: fremder Eltern-Knoten abgelehnt', function () {
    $fremd = FoodAlchemistConceptCategory::create(['team_id' => $this->childB->id, 'name' => 'Fremd']);
    $svc = app(ConceptService::class);

    expect(fn () => $svc->createCategory($this->childA, 'Unter', $fremd->id))->toThrow(RuntimeException::class, 'nicht sichtbar')
        ->and(fn () => $svc->createKlasse($this->childA, 'Unter', $svc->createKlasse($this->childB, 'Fremd')->id))->toThrow(RuntimeException::class, 'nicht sichtbar');
});

it('Regeneration: fremdes Gerät abgelehnt', function () {
    $gericht = FoodAlchemistRecipe::create(['team_id' => $this->childA->id, 'recipe_key' => 'g', 'name' => 'Gericht', 'status' => 'approved', 'is_sales_recipe' => true]);
    $geraet = FoodAlchemistVocabRegenerationDevice::create(['team_id' => $this->childB->id, 'slug' => 'kombi', 'name' => 'Kombidämpfer']);

    expect(fn () => app(SalesRecipeService::class)->upsertRegeneration($this->childA, $gericht->id, ['device_vocab_id' => $geraet->id]))
        ->toThrow(RuntimeException::class, 'nicht sichtbar');
});

it('Format anlegen: Status ≠ Entwurf nur mit Rolle Kuratieren', function () {
    $this->actingAs($this->makeUser($this->childA, 'Gast', 'viewer'));
    expect(fn () => app(FormatService::class)->create($this->childA, ['name' => 'F', 'status' => 'active']))->toThrow(FaRechtFehltException::class);
    expect(app(FormatService::class)->create($this->childA, ['name' => 'F'])->status)->toBe('draft');

    $this->actingAs($this->makeUser($this->childA, 'Koch', 'member'));
    expect(app(FormatService::class)->create($this->childA, ['name' => 'G', 'status' => 'active'])->status)->toBe('active')
        ->and(fn () => app(FormatService::class)->create($this->childA, ['name' => 'H', 'status' => 'quatsch']))->toThrow(RuntimeException::class, 'Unbekannter Format-Status');
});

it('Speisekarte gericht_ref: abonnierte Fassung erlaubt, Master ohne Abo und fremdes Team abgelehnt (Sicht-Regel inkl. Abo)', function () {
    $master = \Platform\Core\Models\Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $master->id]);
    $mandant = \Platform\Core\Models\Team::create(['name' => 'Mandant', 'user_id' => 1, 'personal_team' => false]);
    \Platform\FoodAlchemist\Support\TeamAncestryRegistry::flushAll();
    \Platform\FoodAlchemist\Support\StammPaket::freischalten($mandant);
    $kurator = $this->makeUser($master, 'Kurator', 'owner');
    $this->actingAs($this->makeUser($mandant, 'Mandant Chef', 'owner'));

    $jus = $this->makeRecipe($master, 'Kalbsjus', ['is_sales_recipe' => true]);
    $jusF = $this->makeRecipe($master, 'Kalbsjus', ['is_sales_recipe' => true, 'ist_fassung' => true, 'fassung_von_id' => $jus->id]);
    $geheim = $this->makeRecipe($master, 'Master-Geheimrezept', ['is_sales_recipe' => true]);
    $fremd = $this->makeRecipe($this->childB, 'Fremd', ['is_sales_recipe' => true]);
    $inhalte = app(\Platform\FoodAlchemist\Services\InhaltsFreigabeService::class);
    $sammlung = $inhalte->sammlungAnlegen($master, 'Basis', null, $kurator);
    $inhalte->sammlungHinzu($master, $sammlung, 'recipe', [$jus->id], $kurator);
    app(\Platform\FoodAlchemist\Services\AboService::class)->abonnieren($master, $mandant, $sammlung, $kurator);

    $svc = app(SpeisekarteService::class);
    $karte = $svc->create($mandant, ['name' => 'Mandanten-Karte']);
    $rubrik = $svc->addRubrik($mandant, $karte->id, ['title' => 'Hauptgänge']);

    expect($svc->addPosition($mandant, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $jusF->id])->sales_recipe_id)->toBe($jusF->id)
        ->and(fn () => $svc->addPosition($mandant, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $geheim->id]))->toThrow(RuntimeException::class, 'sichtbares VK-Gericht')
        ->and(fn () => $svc->addPosition($mandant, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $fremd->id]))->toThrow(RuntimeException::class, 'sichtbares VK-Gericht');
});
