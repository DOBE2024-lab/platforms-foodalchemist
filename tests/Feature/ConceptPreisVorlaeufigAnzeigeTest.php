<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Browser;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Services\Ausgabe\AngebotService;
use Platform\FoodAlchemist\Services\Concepter\ConceptService;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Konfigurator\KonfiguratorPricingService;
use Platform\FoodAlchemist\Services\Planung\LeitstelleService;
use Platform\FoodAlchemist\Services\Ausgabe\OfferCompositionService;
use Platform\FoodAlchemist\Services\Ausgabe\SpeisekarteService;
use Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanService;
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
    $this->concepts->fillSlot($t, $this->concepts->addSlot($t, $this->teil->id, ['role' => 'Dessert'])->id, ['recipe_id' => $ohneVk->id]);
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

it('PR C · Kunden-PDF: Konzept-Block mit Teil-Preis trägt „(vorläufig)“, nach manuellem Konzept-Preis nicht mehr', function () {
    $fbs = app(FoodbookService::class);
    $fb = $fbs->create($this->rootTeam, ['label' => 'Kunden-FB', 'personen' => 30]);
    $kap = $fbs->addKapitel($this->rootTeam, $fb->id, ['title' => 'Buffet']);
    $fbs->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);
    $pdf = fn () => view('foodalchemist::dokumente.foodbook', $fbs->dokumentDaten($this->rootTeam, $fb->fresh(), false) + ['istPdf' => false])->render();

    $html = $pdf();
    expect(substr_count($html, '(vorläufig)'))->toBe(1)->and($html)->toContain('12,00')
        ->not->toContain('VK fehlt')->not->toContain('Wareneinsatz');   // Kundensicht: nur „vorläufig“, keine Interna

    $this->concepts->update($this->rootTeam, $this->teil->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 25]);
    expect($pdf())->not->toContain('(vorläufig)')->toContain('25,00');
});

it('PR C · Präsentation: Vorschau, veröffentlichter Link und Kundenseite zeigen „vorläufig“, nach manuellem Preis nicht', function () {
    $fbs = app(FoodbookService::class);
    $fb = $fbs->create($this->rootTeam, ['label' => 'Praes-FB', 'personen' => 30]);
    $kap = $fbs->addKapitel($this->rootTeam, $fb->id, ['title' => 'Buffet']);
    $fbs->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);
    $praes = app(\Platform\FoodAlchemist\Services\Ausgabe\PresentationService::class);
    $vorschau = fn () => view('foodalchemist::presentation.show', ['snapshot' => $praes->previewData($this->rootTeam, 'foodbook', $fb->id)])->render();

    $snap = $praes->previewData($this->rootTeam, 'foodbook', $fb->id);
    $block = collect($snap['content']['sections'][0]['blocks'])->firstWhere('kind', 'concept_ref');
    expect($block['price']['vorlaeufig'])->toBeTrue()->and($snap['content']['total']['vorlaeufig'])->toBeTrue()
        ->and($snap['content']['sections'][0]['preis']['vorlaeufig'] ?? false)->toBeTrue();
    $html = $vorschau();
    expect($html)->toContain('data-preis-vorlaeufig')->toContain('vorläufig')->not->toContain('VK fehlt');

    $this->get(route('foodalchemist.foodbooks.praesentation', $fb->id))->assertOk()->assertSee('data-preis-vorlaeufig', false);
    $res = $praes->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    auth()->logout();
    $this->get('/p/foodbook/' . $res['token'])->assertOk()->assertSee('data-preis-vorlaeufig', false);
    $this->actingAs($this->user);

    $this->concepts->update($this->rootTeam, $this->teil->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 25]);
    expect($vorschau())->not->toContain('data-preis-vorlaeufig')   // Gesamt las den Konzept-Preis über einen schmalen Eager-Load ohne price_mode (12 statt 25)
        ->and($fbs->gesamt($this->rootTeam, $fb->fresh())['vk_pro_person'])->toEqual(25.0);
});

it('PR C · Angebot: Gesamtpreis trägt vk_fehlt in Dokument, Karte und Präsentation; ein gesetzter Angebotspreis nicht', function () {
    $comp = app(OfferCompositionService::class);
    $svc = app(AngebotService::class);
    $angebot = $svc->create($this->rootTeam, ['name' => 'Gala 2027', 'personen' => 40]);
    $kap = $comp->addKapitel($this->rootTeam, $angebot->id, ['title' => 'Buffet']);
    $comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);

    expect($svc->kalkulation($this->rootTeam, $angebot->fresh())['vk_fehlt'])->toBeTrue();
    $dok = view('foodalchemist::dokumente.angebot', $svc->dokumentDaten($this->rootTeam, $angebot->fresh()) + ['istPdf' => false])->render();
    expect(substr_count($dok, '(vorläufig)'))->toBeGreaterThanOrEqual(2)->and($dok)->not->toContain('VK fehlt');   // Preis pro Person + Gesamt
    $karte = view('foodalchemist::dokumente.angebot-karte', $svc->karteDaten($this->rootTeam, $angebot->id) + ['istPdf' => false])->render();
    expect($karte)->toContain('(vorläufig)');
    $snap = app(\Platform\FoodAlchemist\Services\Ausgabe\PresentationService::class)->previewData($this->rootTeam, 'angebot', $angebot->id);
    expect($snap['content']['total']['vorlaeufig'])->toBeTrue()->and($snap['content']['preis_aufschluesselung']['vorlaeufig'] ?? false)->toBeTrue();

    \Illuminate\Support\Facades\DB::table('foodalchemist_offers')->where('id', $angebot->id)->update(['price_mode' => 'manuell', 'total_price' => 1200]);
    expect($svc->kalkulation($this->rootTeam, $angebot->fresh())['vk_fehlt'])->toBeFalse();
    $dok = view('foodalchemist::dokumente.angebot', $svc->dokumentDaten($this->rootTeam, $angebot->fresh()) + ['istPdf' => false])->render();
    expect($dok)->toMatch('/Gesamt<\/td><td class="right total">[^<]*€<\/td>/');   // Gesamt ohne Hinweis
});

it('PR C · Republish preserve: Preis UND Merker bleiben eingefroren, auto zieht beide live', function () {
    $fbs = app(FoodbookService::class);
    $praes = app(\Platform\FoodAlchemist\Services\Ausgabe\PresentationService::class);
    $fb = $fbs->create($this->rootTeam, ['label' => 'Freeze-FB', 'personen' => 30]);
    $kap = $fbs->addKapitel($this->rootTeam, $fb->id, ['title' => 'Buffet']);
    $fbs->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);
    $exp = now()->addDays(30)->toDateString();
    $block = fn () => collect(FoodAlchemistFoodbook::find($fb->id)->presentation_snapshot_json['content']['sections'][0]['blocks'])->firstWhere('kind', 'concept_ref')['price'];
    $total = fn () => FoodAlchemistFoodbook::find($fb->id)->presentation_snapshot_json['content']['total'];

    $praes->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => $exp]);   // einfrieren: 12 €, vorläufig
    expect($block()['pp'])->toEqual(12.0)->and($block()['vorlaeufig'])->toBeTrue()->and($total()['vorlaeufig'])->toBeTrue();

    // Preis wird vollständig (hier als gesetzter Konzept-Preis), dann preserve: alter Preis und alter Merker
    $this->concepts->update($this->rootTeam, $this->teil->id, ['price_mode' => 'manuell', 'price_per_person_manual' => 30]);
    $praes->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => $exp]);
    expect($block()['pp'])->toEqual(12.0)->and($block()['vorlaeufig'])->toBeTrue()
        ->and($total()['vk_pro_person'])->toEqual(12.0)->and($total()['vorlaeufig'])->toBeTrue();

    $praes->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => $exp, 'price_mode' => 'auto']);
    expect($block()['pp'])->toEqual(30.0)->and($block()['vorlaeufig'])->toBeFalse()->and($total()['vorlaeufig'])->toBeFalse();
});

it('PR C · Präsentations-Layout bringt die dezente Regel .pt-vorlaeufig mit, im hellen und im dunklen Design', function () {
    $fbs = app(FoodbookService::class);
    $fb = $fbs->create($this->rootTeam, ['label' => 'Layout-FB']);
    $kap = $fbs->addKapitel($this->rootTeam, $fb->id, ['title' => 'Buffet']);
    $fbs->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $this->teil->id]);
    $praes = app(\Platform\FoodAlchemist\Services\Ausgabe\PresentationService::class);
    foreach (['editorial', 'kiosk'] as $design) {   // kiosk = dunkle Palette
        $html = view('foodalchemist::presentation.show', ['snapshot' => $praes->previewData($this->rootTeam, 'foodbook', $fb->id, $design)])->render();
        expect($html)->toContain('.pt-vorlaeufig {')->toContain('class="pt-vorlaeufig" data-preis-vorlaeufig');
    }
});

it('PR C · KapitelPreis: vorlaeufig nur am festen Preis, nie an der Spanne', function () {
    expect(\Platform\FoodAlchemist\Support\KapitelPreis::ausKapitel(['vk_pro_person' => 12.0, 'vk_vorlaeufig' => true], false))->toHaveKey('vorlaeufig', true)
        ->and(\Platform\FoodAlchemist\Support\KapitelPreis::ausKapitel(['vk_pro_person' => 12.0, 'vk_fehlt' => true], false))->toHaveKey('vorlaeufig', true)
        ->and(\Platform\FoodAlchemist\Support\KapitelPreis::ausKapitel(['vk_pro_person' => 12.0], false))->not->toHaveKey('vorlaeufig')
        ->and(\Platform\FoodAlchemist\Support\KapitelPreis::ausKapitel(['vk_pro_person' => null, 'vk_fehlt' => true, 'preis_range' => ['min' => 10, 'max' => 20]], true))->not->toHaveKey('vorlaeufig');
});

it('Designsystem-Seite zeigt den Baustein „vorläufig“', function () {
    Livewire::test(\Platform\FoodAlchemist\Livewire\UiKatalog::class)->assertSeeHtml('data-ui-katalog-vorlaeufig')->assertSeeHtml('data-preis-vorlaeufig');
});

it('Konzept-Liste im Concepter zeigt am Teil-Preis „vorläufig“', function () {
    Livewire::test(Browser::class)->assertSeeHtml('data-preis-vorlaeufig')->assertSee('Sommerfest');
});
