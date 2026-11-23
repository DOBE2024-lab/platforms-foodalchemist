<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichungDelta;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\Plattform\AboService;
use Platform\FoodAlchemist\Services\ConceptFassungService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Services\Preis\PriceService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\TeamVkService;
use Platform\FoodAlchemist\Support\StammPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Plan 98 B (k), Kurator 23.11.2026: im Abo rechnet eine Darreichung mit Deltas (jede Variante) je Komponente mit den
 * Team-Preisen des Mandanten — dieselbe Rechnung wie beim Besitzer (DarreichungService::komponentenSumme). Vorher „kein VK“
 * mit Grund deltas_im_abo_offen. Fehlt einer genutzten Komponente der Team-Preis: kein VK, Grund komponente_ohne_team_preis.
 * Aufbau wie Spec82KonzeptFassungTest: Kalbsjus (Knochen + Butter), Standard 200 g, Variante „klein“ = 50 % vom Standard; das Paket ist
 * seit 98 · 2.6c ein Paket-Konzept (kind=paket), seine Fassung hängt die kleine Portion samt Deltas an die Rezept-Fassung (Spec 82 D9).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->soul = Team::create(['name' => 'Soulkitchen', 'user_id' => 1, 'personal_team' => false]);
    $this->neu = Team::create(['name' => 'Neukunde', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    foreach ([$this->broich, $this->soul, $this->neu] as $t) {
        StammPaket::freischalten($t);
    }
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');
    $this->neuChef = $this->makeUser($this->neu, 'Neu Chef', 'owner');
    $this->actingAs($this->kurator);

    $sup = FoodAlchemistSupplier::create(['team_id' => null, 'name' => 'Stamm-Großhandel']);
    $mk = function (string $name) use ($sup) {
        $gp = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'pp|' . $name, 'name' => $name]);
        $la = FoodAlchemistSupplierItem::create(['team_id' => null, 'supplier_id' => $sup->id, 'designation' => $name . ' 1 kg', 'qty' => 1.0, 'unit_code' => 'kg']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => null, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);

        return [$gp->refresh(), $la];
    };
    [$knochen, $laKnochen] = $mk('Kalbsknochen');
    [$butter, $this->laButter] = $mk('Butter');

    $this->gericht = $this->makeRecipe($this->master, 'Kalbsjus am Teller', ['is_sales_recipe' => true]);
    $this->makeIngredient($this->gericht, 'Kalbsknochen', $knochen, '1000');
    $this->makeIngredient($this->gericht, 'Butter', $butter, '100', 2);
    app(RecipeRecomputeService::class)->recomputePipeline($this->gericht->id);

    $this->teller = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $this->klein = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'klein', 'label' => 'Klein']);
    $dar = app(DarreichungService::class);
    $this->standard = $this->grammaturAltbestand($dar->anlegen($this->master, $this->gericht->id, $this->teller->id), 200);   // Plan 98 B: Grammatur als Altbestand-Fixture (freie Grammatur ist über den Service gesperrt)
    $this->kleine = $dar->setzeAnteil($this->master, $dar->anlegen($this->master, $this->gericht->id, $this->klein->id)->id, 50);   // Plan 98 B: Variante als Anteil vom Standard (statt freier Grammatur): 100 von 200 g
    $this->portion = FoodAlchemistVocabEinheit::create(['team_id' => $this->master->id, 'slug' => 'portion', 'display_de' => 'Portion', 'dimension' => 'count', 'default_in_g' => null]);

    $cs = app(ConceptService::class);
    $this->paket = $cs->createPaket($this->master, ['name' => 'Jus-Duo']);
    $slot = $cs->addSlot($this->master, $this->paket->id, ['role' => 'Beilage']);
    $cs->fillSlot($this->master, $slot->id, ['sales_recipe_id' => $this->gericht->id, 'quantity' => 1, 'unit_vocab_id' => $this->portion->id]);
    $cs->setSlotDarreichung($this->master, $slot->id, $this->kleine->id);   // das Paket führt die kleine Portion

    $this->fassungen = app(FassungService::class);
    $this->fassungen->veroeffentlichen($this->master, $this->gericht->id, 'Erst', $this->kurator);
    $inhalte = app(InhaltsFreigabeService::class);
    $sid = $inhalte->sammlungAnlegen($this->master, 'Pakete', null, $this->kurator);
    $inhalte->sammlungHinzu($this->master, $sid, 'concept', [$this->paket->id], $this->kurator);
    foreach ([$this->broich, $this->soul, $this->neu] as $t) {
        app(AboService::class)->abonnieren($this->master, $t, $sid, $this->kurator);
    }

    $p = app(PriceService::class);
    $p->createTeamPrice($this->broich, $laKnochen, 4.00);
    $p->createTeamPrice($this->broich, $this->laButter, 10.00);
    $p->createTeamPrice($this->soul, $laKnochen, 5.00);
    $p->createTeamPrice($this->soul, $this->laButter, 12.00);
    $p->createTeamPrice($this->neu, $this->laButter, 10.00);   // Neukunde: Knochen ohne Preis → unvollständig

    // Paket-Fassung: die gewählte kleine Portion hängt samt Deltas an der Rezept-Fassung
    $r = app(ConceptFassungService::class)->veroeffentlichen($this->master, $this->paket->id, 'Kleine Portion', $this->kurator);
    $zeile = FoodAlchemistConcept::mitFassungen()->findOrFail($r['fassung_id'])->slots()->whereNotNull('sales_recipe_id')->sole();
    $this->kleinF = FoodAlchemistRecipeDarreichung::findOrFail($zeile->presentation_id);
    $this->gerichtF = FoodAlchemistRecipe::mitFassungen()->findOrFail($this->kleinF->recipe_id);
    expect($this->kleinF->is_standard)->toBeFalse()->and($this->kleinF->deltas()->count())->toBe(2);
    $this->deltaF = fn (string $name) => FoodAlchemistRecipeDarreichungDelta::where('presentation_id', $this->kleinF->id)
        ->whereHas('ingredient', fn ($q) => $q->where('raw_text', $name))->sole();
});

it('Variante mit Deltas im Abo: Preis aus den Team-Preisen je Komponente, je Mandant verschieden', function () {
    $vk = app(TeamVkService::class);
    $knochen = (float) ($this->deltaF)('Kalbsknochen')->quantity_override_g;
    $butter = (float) ($this->deltaF)('Butter')->quantity_override_g;
    expect($knochen)->toBeGreaterThan(0.0)->and($butter)->toBeGreaterThan(0.0);

    $b = $vk->fuer($this->gerichtF, $this->broich, null, $this->kleinF);
    $s = $vk->fuer($this->gerichtF, $this->soul, null, $this->kleinF);
    expect($b['grund'])->toBeNull()->and($b['quelle'])->toBe('team_kalkulation')
        ->and($b['mek'])->toBe(round($knochen * 0.004 + $butter * 0.010, 4))        // Broich: Knochen 4 €/kg, Butter 10 €/kg
        ->and($s['mek'])->toBe(round($knochen * 0.005 + $butter * 0.012, 4))        // Soulkitchen: 5 €/kg und 12 €/kg
        ->and($b['vk'])->toBeGreaterThan(0.0)->and($b['vk'])->toBeLessThan($s['vk']);

    // die kleine Portion kostet weniger als der Standard desselben Teams
    expect($b['vk'])->toBeLessThan($vk->fuer($this->gerichtF, $this->broich)['vk']);
});

it('fehlt einer genutzten Komponente der Team-Preis: kein VK (nie 0, kein Teilpreis); weggelassen stört sie nicht', function () {
    $vk = app(TeamVkService::class);
    $n = $vk->fuer($this->gerichtF, $this->neu, null, $this->kleinF);              // Neukunde: Knochen ohne Preis
    expect($n['vk'])->toBeNull()->and($n['mek'])->toBeNull()->and($n['grund'])->toBe('komponente_ohne_team_preis');

    ($this->deltaF)('Kalbsknochen')->update(['omitted' => true, 'quantity_override_g' => 0]);
    $ohne = $vk->fuer($this->gerichtF, $this->neu, null, $this->kleinF->fresh());
    expect($ohne['grund'])->toBeNull()
        ->and($ohne['mek'])->toBe(round((float) ($this->deltaF)('Butter')->quantity_override_g * 0.010, 4))
        ->and($ohne['vk'])->toBeGreaterThan(0.0);
});
