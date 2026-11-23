<?php

use Illuminate\Support\Facades\Storage;
use Platform\Core\Contracts\LLMProviderContract;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\Foodbook\FoodbookService;
use Platform\FoodAlchemist\Services\Concepter\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (S4): Rauchtest vor dem Zerlegen von FoodbookService. Jedes MCP-Tool, das FoodbookService nutzt, läuft einmal mit
 * gültigen Eingaben und liefert success — so fällt ein beim Schnitt verlorener Fassaden-Delegator sofort auf.
 * Kein Fachtest: Inhalte prüfen FoodbookServiceTest, FoodbookBrandingTest, Spec98McpBilderFotosTest und FoodbookKiKundentextTest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    Storage::fake('public');
    $this->user = $this->makeUser($this->rootTeam, 'Foodbook');
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->svc = app(FoodbookService::class);

    // Fixture: Entwurf-Foodbook mit zwei Kapiteln, Konzept mit Gericht, drei Blöcken (Konzept, Gericht, Text) und einem Format.
    $this->fb = $this->makeFoodbook($this->rootTeam, 'Rauch-Buch', ['status' => 'entwurf', 'personen' => 50]);
    $this->kap1 = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Empfang']);
    $this->kap2 = $this->svc->addKapitel($this->rootTeam, $this->fb->id, ['title' => 'Hauptgang']);
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rauch-Zander', ['is_sales_recipe' => true]);
    $this->concept = $this->makeConcept($this->rootTeam, 'Rauch-Menü', ['consumer_name' => 'Rauch-Genuss', 'price_per_person_cache' => 40.00]);
    $this->makeConceptSlot($this->concept, ['sales_recipe_id' => $this->gericht->id, 'wording' => 'Zander auf Linsen']);
    $this->blockKonzept = $this->svc->addBlock($this->rootTeam, $this->kap1->id, ['type' => 'concept_ref', 'concept_id' => $this->concept->id]);
    $this->blockGericht = $this->svc->addBlock($this->rootTeam, $this->kap1->id, ['type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id]);
    $this->blockText = $this->svc->addBlock($this->rootTeam, $this->kap1->id, ['type' => 'text', 'label' => 'Notiz']);
    $this->format = app(FormatService::class)->create($this->rootTeam, ['name' => 'RAUCH.CORNER', 'consumer_name' => 'Rauch Corner', 'story' => 'Hinführung.']);
    app(FormatService::class)->slotConceptEinfuegen($this->rootTeam, $this->format->id, $this->concept->id);
    $this->zielgruppe = $this->svc->zielgruppeAnlegen($this->rootTeam, ['name' => 'Rauch-Gast']);

    // 4×4-Pixel-PNG für die Bild-Uploads (base64, wie vom MCP-Client geliefert)
    $bild = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($bild);
    $this->datei = ['base64' => base64_encode((string) ob_get_clean()), 'name' => 'bild.png'];

    $this->rauch = function (string $tool, array $args) {
        $r = app($tool)->execute($args, $this->ctx);
        expect($r->success)->toBeTrue(class_basename($tool).': '.($r->error ?? json_encode($r->errorCode)));

        return $r;
    };
});

it('Lesen und Vokabular: Liste, Detail, Struktur-Vokabular, Zielgruppen, Pairing-Inspiration', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\FoodbooksListTool::class, []);
    $r(Tools\FoodbooksGetTool::class, ['id' => $this->fb->id]);
    $r(Tools\StrukturVokabularGetTool::class, []);
    $r(Tools\ZielgruppenGetTool::class, []);
    $r(Tools\ZielgruppenPostTool::class, ['name' => 'Rauch-Zielgruppe', 'description' => 'Rauchtest', 'sort_order' => 10]);
    // Seeds ohne Treffer im Anker-Vokabular → leeres Ergebnis, aber success (kein Fachtest)
    $r(Tools\PairingInspirationGetTool::class, ['seeds' => ['tomate'], 'modus' => 'datenbank', 'limit_pro_seed' => 3]);
});

it('Foodbook anlegen, ändern, Status, Duplikat', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $neu = $r(Tools\FoodbooksPostTool::class, [
        'label' => 'Rauch-Neu', 'jahr' => 2027, 'personen' => 40, 'description' => 'Rauchtest',
        'kapitel' => ['Empfang', 'Dessert'], 'zielgruppen' => [$this->zielgruppe->id], 'creative_mode_default' => 'hybrid',
    ]);
    expect($neu->data['foodbook']['id'])->toBeGreaterThan(0);
    $r(Tools\FoodbooksPutTool::class, ['id' => $this->fb->id, 'felder' => ['label' => 'Rauch-Buch 2', 'personen' => 60, 'description' => 'Neu']]);
    $r(Tools\FoodbooksDuplicateTool::class, ['id' => $this->fb->id]);
    $r(Tools\FoodbooksStatusTool::class, ['id' => $this->fb->id, 'status' => 'aktiv']);
});

it('Kapitel: anlegen, ändern, verschieben, ordnen, Wording', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $neu = $r(Tools\FoodbookKapitelPostTool::class, [
        'foodbook_id' => $this->fb->id, 'title' => 'Dessert', 'consumer_title' => 'Süßes Finale', 'claim' => 'Zum Schluss', 'price_per_person' => 8.5,
    ]);
    $r(Tools\FoodbookKapitelPutTool::class, [
        'kapitel_id' => $this->kap1->id, 'title' => 'Empfang neu', 'claim' => 'Willkommen', 'target_count' => 3, 'price_anchor' => 40,
        'pricing_mode' => 'paket', 'creative_mode' => 'hybrid', 'fortschritt' => 'in_arbeit', 'zielgruppen' => [$this->zielgruppe->id],
    ]);
    $r(Tools\FoodbookKapitelMoveTool::class, ['kapitel_id' => $this->kap2->id, 'new_parent_id' => $this->kap1->id]);
    $r(Tools\FoodbookKapitelReorderTool::class, ['foodbook_id' => $this->fb->id, 'ids' => [$neu->data['kapitel']['id'], $this->kap1->id]]);
    // Ohne Kapitel-Schreibstil gibt es nichts zu betexten → 0 Blöcke, aber success und kein KI-Aufruf
    $r(Tools\FoodbookKapitelWordingTool::class, ['kapitel_id' => $this->kap1->id]);
});

it('Blöcke: anlegen (mit Staffel), ändern, ordnen, Variantengruppe', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $neu = $r(Tools\FoodbookBlocksPostTool::class, [
        'chapter_id' => $this->kap2->id, 'type' => 'recipe_ref', 'sales_recipe_id' => $this->gericht->id, 'label' => 'Zander',
        'price_value' => 12.5, 'price_basis' => 'pro_stueck', 'staffel' => [['min_persons' => 10, 'price' => 11.0]],
    ]);
    expect($neu->data['block']['id'])->toBeGreaterThan(0);
    $r(Tools\FoodbookBlocksPutTool::class, ['block_id' => $this->blockText->id, 'felder' => ['label' => 'Notiz neu', 'customer_text' => 'Kundentext', 'visible' => true]]);
    $r(Tools\FoodbookBlocksReorderTool::class, ['kapitel_id' => $this->kap1->id, 'ids' => [$this->blockText->id, $this->blockGericht->id, $this->blockKonzept->id]]);
    $r(Tools\FoodbookBlocksVariantGroupTool::class, ['block_ids' => [$this->blockKonzept->id, $this->blockGericht->id]]);
});

it('Format als Kapitel einfügen', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\FoodbookInsertFormatTool::class, ['foodbook_id' => $this->fb->id, 'format_id' => $this->format->id]);
});

it('Branding und Bilder: Farben, Logo, Cover, Kapitelbild, Kapitel-Galerie', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\FoodbooksBrandingTool::class, ['id' => $this->fb->id, 'brand_color' => '#336699', 'band_color' => '#99ccff', 'footer_text' => 'Rauch GmbH']);
    $r(Tools\FoodbooksImageTool::class, ['id' => $this->fb->id, 'ziel' => 'logo', 'datei' => $this->datei]);
    $r(Tools\FoodbooksImageTool::class, ['id' => $this->fb->id, 'ziel' => 'cover', 'datei' => $this->datei]);
    $r(Tools\FoodbooksImageTool::class, ['id' => $this->fb->id, 'ziel' => 'kapitel_bild', 'kapitel_id' => $this->kap1->id, 'datei' => $this->datei]);
    $r(Tools\FoodbooksImageTool::class, ['id' => $this->fb->id, 'ziel' => 'kapitel_galerie', 'kapitel_id' => $this->kap1->id, 'datei' => $this->datei]);
});

it('CRM-Kunde verknüpfen (mit CRM-Modul Firma, sonst lösen)', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    if (class_exists(\Platform\Crm\Models\CrmCompany::class)) {
        $firma = \Platform\Crm\Models\CrmCompany::create(['team_id' => $this->rootTeam->getRootTeam()->id, 'name' => 'Hotel Rauch', 'is_active' => true]);
        $r(Tools\FoodbooksCustomerLinkTool::class, ['id' => $this->fb->id, 'company_id' => $firma->id]);
    } else {
        // Ohne CRM-Modul prüft CrmKunden::pruefe nichts: ohne IDs wird nur gelöst
        $r(Tools\FoodbooksCustomerLinkTool::class, ['id' => $this->fb->id]);
    }
});

it('KI-Texte: Kundentext für Foodbook und Kapitel (Provider-Stub)', function () {
    // Provider-Stub wie in FoodbookKiKundentextTest: liefert werte.text, sonst gäbe es ohne echte KI kein success
    config(['foodalchemist.ai.provider' => 'core']);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'test-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['text' => 'Ein Abend, der beim Zander beginnt.'], 'confidence' => 0.8, 'reasoning' => 'stub']),
                'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void
        {
            $onDelta($this->chat($messages, $options)['content']);
        }

        public function getAvailableModels(): array
        {
            return ['stub'];
        }

        public function getDefaultModel(): string
        {
            return 'stub';
        }

        public function isAvailable(): bool
        {
            return true;
        }
    });

    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\FoodbookKundentextTool::class, ['foodbook_id' => $this->fb->id]);
    $r(Tools\FoodbookKapitelKundentextTool::class, ['kapitel_id' => $this->kap1->id]);
});

it('Löschen am Ende: Block, Kapitelbild-Galerie/Logo entfernen, Kapitel', function () {
    $r = fn (string $t, array $a) => ($this->rauch)($t, $a);
    $r(Tools\FoodbooksImageTool::class, ['id' => $this->fb->id, 'ziel' => 'logo', 'datei' => $this->datei]);
    $r(Tools\FoodbooksImageTool::class, ['id' => $this->fb->id, 'ziel' => 'logo', 'aktion' => 'entfernen', 'confirm' => true]);
    $r(Tools\FoodbookBlocksDeleteTool::class, ['block_id' => $this->blockText->id]);
    $r(Tools\FoodbookKapitelDeleteTool::class, ['kapitel_id' => $this->kap2->id, 'confirm' => true]);
    expect(FoodAlchemistFoodbookBlock::find($this->blockText->id))->toBeNull()
        ->and(FoodAlchemistFoodbookKapitel::find($this->kap2->id))->toBeNull();
});

it('Alle Tools, die FoodbookService nutzen, sind hier abgedeckt', function () {
    $abgedeckt = collect(file(__FILE__))->flatMap(fn ($z) => preg_match_all('/Tools\\\\(\w+Tool)::class/', $z, $m) ? $m[1] : [])->unique()->sort()->values()->all();
    $nutzen = collect(glob(dirname(__DIR__, 2).'/src/Tools/*Tool.php'))
        ->filter(fn ($f) => preg_match('/\bFoodbookService\b/', (string) file_get_contents($f)))
        ->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();

    expect(array_values(array_diff($nutzen, $abgedeckt)))->toBe([]);
});
