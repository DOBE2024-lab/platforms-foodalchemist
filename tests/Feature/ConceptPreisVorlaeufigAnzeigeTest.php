<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\LeitstelleService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * CQ price_incomplete (PR B): die Anzeigestellen reichen den Merker weiter. Ein Konzept-Preis, der nur die Summe der bekannten
 * Positionen ist, bleibt sichtbar und trägt überall „vorläufig“ (x-fa::vorlaeufig); Summen tragen vk_fehlt bzw. ein eigenes Feld.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $t = $this->rootTeam;
    $this->concepts = app(ConceptService::class);

    // „Sommerfest“: ein Gericht ohne VK → Merker true; „Brotkorb“: Cache direkt gesetzt, Merker false
    $ohneVk = FoodAlchemistRecipe::create(['team_id' => $t->id, 'recipe_key' => 'ohne-vk', 'name' => 'Ohne VK',
        'status' => 'approved', 'is_sales_recipe' => true, 'sales_unit_count' => 1]);
    $paket = $this->concepts->createPaket($t, ['name' => 'Brotkorb']);
    \Illuminate\Support\Facades\DB::table('foodalchemist_concepts')->where('id', $paket->id)
        ->update(['price_per_person_cache' => 12, 'ek_per_person_cache' => 4, 'price_incomplete' => false]);
    $this->teil = $this->concepts->create($t, ['name' => 'Sommerfest']);
    $this->concepts->fillSlot($t, $this->concepts->addSlot($t, $this->teil->id, ['role' => 'Station'])->id, ['embedded_concept_id' => $paket->id]);
    $this->concepts->fillSlot($t, $this->concepts->addSlot($t, $this->teil->id, ['role' => 'Dessert'])->id, ['sales_recipe_id' => $ohneVk->id]);
    $this->teil->refresh();
    $this->voll = $paket->refresh();
    expect($this->teil->price_incomplete)->toBeTrue()->and((float) $this->teil->price_per_person_cache)->toBe(12.0);
});

it('x-fa::money mit vorlaeufig zeigt den Betrag und den Hinweis, ohne nur den Betrag', function () {
    $mit = Blade::render('<x-fa::money :value="12" :vorlaeufig="true" />');
    $ohne = Blade::render('<x-fa::money :value="12" />');

    expect($mit)->toContain('12,00')->toContain('data-preis-vorlaeufig')->toContain('vorläufig')
        ->and($ohne)->toContain('12,00')->not->toContain('data-preis-vorlaeufig');
});

it('Angebot: Konzept-Block und additives Format-Kapitel behalten die Teilsumme und tragen vk_fehlt', function () {
    $comp = app(OfferCompositionService::class);
    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Sommer 2027', 'personen' => 40]);
    $kap = $comp->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Buffet']);
    $block = $comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);

    expect($comp->blockPreis($block->fresh('concept'))['vk_fehlt'])->toBeTrue()
        ->and($comp->kapitelAggregat($this->rootTeam, $kap->fresh())['vk_fehlt'])->toBeTrue();

    $format = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommer', 'status' => 'aktiv']);
    foreach ([$this->teil, $this->voll] as $i => $c) {
        FoodAlchemistFormatSlot::create(['team_id' => $this->rootTeam->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $c->id, 'position' => $i]);
    }
    $fk = $comp->insertFormatKapitel($this->rootTeam, $angebot->id, $format->id);
    $agg = $comp->kapitelAggregat($this->rootTeam, $fk->fresh());
    expect($agg['vk_pro_person'])->toBe(24.0)   // Teilsumme 12 + 12, nicht „kein Preis“
        ->and($agg['vk_fehlt'])->toBeTrue()
        ->and($agg['food_cost_percent'])->toBeNull();
});

it('Fund aus PR A · additives Format-Kapitel mit eigenem Teil-Preis-Konzept: Teilsumme, vk_fehlt, kein Food-Cost', function () {
    $comp = app(OfferCompositionService::class);
    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Herbst 2027', 'personen' => 30]);
    $format = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'Herbst', 'status' => 'aktiv']);
    FoodAlchemistFormatSlot::create(['team_id' => $this->rootTeam->id, 'format_id' => $format->id, 'type' => 'concept', 'concept_id' => $this->teil->id, 'position' => 0]);

    $agg = $comp->kapitelAggregat($this->rootTeam, $comp->insertFormatKapitel($this->rootTeam, $angebot->id, $format->id)->fresh());
    expect($agg['vk_pro_person'])->toBe(12.0)   // vorher (A allein): null, weil preisWerte die Teilsumme als „kein Preis“ las
        ->and($agg['vk_fehlt'])->toBeTrue()
        ->and($agg['food_cost_percent'])->toBeNull();
});

it('PR C · Angebot: ein manueller Kapitelpreis hebt vk_fehlt auf, wie im Foodbook', function () {
    $comp = app(OfferCompositionService::class);
    $angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Winter 2027', 'personen' => 20]);
    $kap = $comp->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Buffet']);
    $comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);
    expect($comp->kapitelAggregat($this->rootTeam, $kap->fresh())['vk_fehlt'])->toBeTrue();

    \Illuminate\Support\Facades\DB::table('foodalchemist_offer_chapters')->where('id', $kap->id)->update(['price_mode' => 'manuell', 'price_per_person' => 25]);
    $agg = $comp->kapitelAggregat($this->rootTeam, $kap->fresh());
    expect($agg['vk_pro_person'])->toBe(25.0)->and($agg['vk_fehlt'])->toBeFalse();
});

it('Speisekarte und Speiseplan: Konzept-Eintrag trägt vorläufig, ein Linienpreis hebt es auf', function () {
    $pos = (new FoodAlchemistSpeisekartePosition(['type' => 'menue_ref', 'concept_id' => $this->teil->id]))->setRelation('concept', $this->teil);
    expect(app(SpeisekarteService::class)->positionPreis($pos)['vorlaeufig'])->toBeTrue()
        ->and(app(SpeisekarteService::class)->positionEkVk($pos)['vorlaeufig'])->toBeTrue();

    $e = (new FoodAlchemistSpeiseplanEintrag(['concept_id' => $this->teil->id]))->setRelation('concept', $this->teil);
    expect(app(SpeiseplanService::class)->eintragPreis($e)['vorlaeufig'])->toBeTrue();
    $voll = (new FoodAlchemistSpeiseplanEintrag(['concept_id' => $this->voll->id]))->setRelation('concept', $this->voll);
    expect(app(SpeiseplanService::class)->eintragPreis($voll)['vorlaeufig'] ?? false)->toBeFalse();
});

it('Konfigurator: Speisenpreis aus dem Cache ist vorläufig, ein manueller Preis nicht', function () {
    $pricing = app(KonfiguratorPricingService::class);
    expect($pricing->speisenPreis($this->teil)['vorlaeufig'])->toBeTrue()
        ->and($pricing->speisenPreis($this->voll)['vorlaeufig'])->toBeFalse();

    $this->concepts->update($this->rootTeam, $this->teil->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 25]);
    expect($pricing->speisenPreis($this->teil->refresh())['vorlaeufig'])->toBeFalse();
});

it('Leitstelle: Schritt Preise ist „teil“ mit Hinweis, solange ein Konzept vorläufig ist', function () {
    $fbs = app(FoodbookService::class);
    $fb = $fbs->create($this->rootTeam, ['label' => 'Sommer-FB']);
    $kap = $fbs->addKapitel($this->rootTeam, $fb->id, ['title' => 'Buffet']);
    $fbs->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);

    $preise = collect(app(LeitstelleService::class)->checkliste($this->rootTeam, $fb->fresh()))->firstWhere('key', 'preise');
    expect($preise['status'])->toBe('teil')->and($preise['hinweis'])->toContain('vorläufigem Preis');

    $baum = app(LeitstelleService::class)->speisenBaum($this->rootTeam, $fb->fresh());
    expect(collect($baum)->flatMap(fn ($k) => $k['positionen'])->firstWhere('art', 'paket')['preis_vorlaeufig'])->toBeTrue();
});

it('PR C · Foodbook: Kapitel und Gesamt behalten die Teilsumme, tragen vk_fehlt, keine Wareneinsatz-Quote', function () {
    $fbs = app(FoodbookService::class);
    $fb = $fbs->create($this->rootTeam, ['label' => 'Sommer-FB', 'personen' => 50]);
    $kap = $fbs->addKapitel($this->rootTeam, $fb->id, ['title' => 'Buffet']);
    $fbs->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);

    $agg = $fbs->kapitelAggregat($this->rootTeam, $kap->fresh());
    expect($agg['vk_pro_person'])->toBe(12.0)->and($agg['vk_fehlt'])->toBeTrue()->and($agg['food_cost_percent'])->toBeNull();
    $gesamt = $fbs->gesamt($this->rootTeam, $fb->fresh());
    expect($gesamt['vk_fehlt'])->toBeTrue()->and($gesamt['food_cost_percent'])->toBeNull()
        ->and($fbs->foodbookWareneinsatzAmpel($this->rootTeam, $fb->fresh())['ist_pct'])->toBeNull()
        ->and($fbs->wareneinsatzAmpel($this->rootTeam, $fb->fresh(), $kap->fresh())['status'])->toBe('unbekannt');

    $dok = $fbs->dokumentDaten($this->rootTeam, $fb->fresh(), intern: true);
    expect($dok['kapitel'][0]['vk_vorlaeufig'])->toBeTrue()->and($dok['kapitel'][0]['bloecke'][0]['preis_vorlaeufig'])->toBeTrue();

    $board = collect(app(LeitstelleService::class)->kapitelBoard($this->rootTeam, $fb->fresh()))->firstWhere('kapitel_id', $kap->id);
    expect($board['aggregat']['vk_fehlt'])->toBeTrue()->and($board['positionen'][0]['preis_vorlaeufig'])->toBeTrue();

    // ein gesetzter Kapitelpreis ist nicht vorläufig
    \Illuminate\Support\Facades\DB::table('foodalchemist_foodbook_chapters')->where('id', $kap->id)->update(['price_mode' => 'manuell', 'price_per_person' => 20]);
    expect($fbs->kapitelAggregat($this->rootTeam, $kap->fresh())['vk_fehlt'])->toBeFalse();
});

it('PR C · ein vollständig bepreistes Konzept im Foodbook ist nicht vorläufig', function () {
    $fbs = app(FoodbookService::class);
    $fb = $fbs->create($this->rootTeam, ['label' => 'Voll-FB']);
    $kap = $fbs->addKapitel($this->rootTeam, $fb->id, ['title' => 'Brot']);
    $fbs->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->voll->id]);

    expect($fbs->kapitelAggregat($this->rootTeam, $kap->fresh())['vk_fehlt'])->toBeFalse()
        ->and($fbs->gesamt($this->rootTeam, $fb->fresh())['vk_fehlt'])->toBeFalse();
});

it('Designsystem-Seite zeigt den Baustein „vorläufig“', function () {
    Livewire::test(\Platform\FoodAlchemist\Livewire\UiKatalog::class)->assertSeeHtml('data-ui-katalog-vorlaeufig')->assertSeeHtml('data-preis-vorlaeufig');
});

it('Konzept-Liste im Concepter zeigt am Teil-Preis „vorläufig“', function () {
    Livewire::test(Browser::class)->assertSeeHtml('data-preis-vorlaeufig')->assertSee('Sommerfest');
});
