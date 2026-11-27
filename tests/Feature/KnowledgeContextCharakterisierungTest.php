<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 3.3 (KI-Strang a) PR0 — Charakterisierung von KnowledgeContextService vor dem Zerlegen.
 *
 * Hält die erzeugten Prompt-Blöcke BIT-GLEICH fest (Block-Text, files_used/dropped, used_by_category, Herkunft mit Scores,
 * Zeichen-Messfelder), dazu die öffentlichen Helfer (Routing-Auflösung, Budget, Tokenizer, Pairing-Namen, Frontmatter,
 * MCP-Lesewege). Ein Korpus deckt jeden Kanal ab: Concept, Achse, Cross-Cutting, Domain, Pairing (Graph), Pairing-Grounding,
 * Zutat-Grounding, Niveau, generische discovery, generische always-Kategorie, Arten-Routing (fachwissen/referenz),
 * Alias-Rückfall (vk.generator → ai_generate_recipe) mit Rezept-Priorität, `_exclude_slugs` und `_max_chars`.
 *
 * Das ist ein Ist-Stand, keine Fachaussage: ändert ein Schnitt hier etwas, war er nicht mechanisch.
 * Golden neu schreiben (nur bei gewollter Verhaltensänderung, im PR begründen): KNOWLEDGE_CONTEXT_GOLDEN_SCHREIBEN=1.
 */
const KNOWLEDGE_CONTEXT_CHARAKTER_GOLDEN = __DIR__.'/../Fixtures/golden/knowledge_context_charakter.json';

function knowledgeContextGolden(string $schluessel, mixed $ist): void
{
    $ist = json_decode(json_encode($ist, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
    $alle = is_file(KNOWLEDGE_CONTEXT_CHARAKTER_GOLDEN) ? json_decode((string) file_get_contents(KNOWLEDGE_CONTEXT_CHARAKTER_GOLDEN), true) : [];
    if (getenv('KNOWLEDGE_CONTEXT_GOLDEN_SCHREIBEN')) {
        $alle[$schluessel] = $ist;
        ksort($alle);
        file_put_contents(KNOWLEDGE_CONTEXT_CHARAKTER_GOLDEN, json_encode($alle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        test()->markTestSkipped('Golden neu geschrieben: '.$schluessel);
    }
    expect($alle)->toHaveKey($schluessel);
    expect($ist)->toBe($alle[$schluessel]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.master_team_id' => $this->rootTeam->id]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    config([
        'foodalchemist.semantic_search.enabled' => false,
        'foodalchemist.ai.knowledge_axis_map' => ['occasion' => ['dinner' => ['event_playbook_gala_fehlt', 'event_playbook_gala']]],
        'foodalchemist.ai.cross_cutting_slugs' => ['test.kc.schmal' => ['synonyme']],
    ]);

    $zeit = '2026-11-01 10:00:00';
    DB::table('foodalchemist_knowledge_routings')->delete();   // Migrations-Standardzeilen raus: nur die Zeilen dieses Tests steuern
    $mkDoc = function (string $slug, string $category, string $inhalt, ?string $art = null, int $version = 1, bool $aktiv = true) use ($zeit) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'title' => 'Titel '.$slug,
            'category' => $category, 'content_md' => $inhalt, 'version' => $version, 'art' => $art,
            'content_hash' => hash('sha256', $slug.$inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => $aktiv ? 1 : 0, 'created_at' => $zeit, 'updated_at' => $zeit,
        ]);
    };
    $this->mkRouting = function (string $feature, ?string $category, string $mode, ?int $maxDocs = null, ?string $art = null) use ($zeit) {
        DB::table('foodalchemist_knowledge_routings')->insert([
            'feature' => $feature, 'category' => $category, 'art' => $art, 'mode' => $mode,
            'max_docs' => $maxDocs, 'max_chars_per_doc' => null, 'created_at' => $zeit, 'updated_at' => $zeit,
        ]);
    };

    // Korpus: je Kanal mindestens ein Treffer und ein Nicht-Treffer.
    $mkDoc('concept-dramaturgie', 'concept', "# Dramaturgie\nSpannungsbogen über die Gänge.");
    $mkDoc('concept-gangaufbau', 'concept', "# Gangaufbau\nLeicht → schwer → leicht.", null, 2);
    $mkDoc('concept-zzz-inaktiv', 'concept', 'inaktiv', null, 1, false);
    $mkDoc('event_playbook_gala', 'event_playbook', "Gala-Dinner: Portionierung, Service-Takt, Erwartung.", 'regel');
    $mkDoc('synonyme', 'cross_cutting', 'Synonyme: Paradeiser = Tomate, Schlagobers = Sahne.');
    $mkDoc('mengen_defaults', 'cross_cutting', 'Mengen: Suppe 250 ml je Portion.');
    $mkDoc('techniken', 'cross_cutting', 'Techniken: Confieren, Sous-vide, Glasieren.');
    $mkDoc('fruchtgemuese-tomate-verwendung', 'domain', 'Tomate: Säure, Süße, Garzeiten.');
    $mkDoc('fonds-bruehen-ansatz', 'domain', 'Fonds: Ansatz, Auszug, Klärung.');
    $mkDoc('gewuerze-thai-curry', 'domain', 'Thai-Curry: Paste, Kokos, Limette.');
    $mkDoc('pairing.basilikum', 'pairing', "# Basilikum\n## Pairings\n- [[tomate|Tomate]]\n- **Erdbeere**\n## Notizen\n- [[nicht|Nicht]]");
    $mkDoc('zutat.tomate--verwendung', 'zutat', 'Tomate Verwendung: roh, geschmort, als Fond-Basis.', 'fachwissen');
    $mkDoc('zutat.tomate--steckbrief', 'zutat', 'Tomate Steckbrief: Nachtschattengewächs.', 'fachwissen');
    $mkDoc('zutat.tomate--verhalten-hitze', 'zutat', 'Tomate Hitze: zerfällt, konzentriert.', 'fachwissen');
    $mkDoc('zutat.tomate--verhalten-saeure', 'zutat', 'Tomate Säure: bremst Garung von Hülsenfrüchten.', 'fachwissen');
    $mkDoc('zutat.tomatillo--verwendung', 'zutat', 'Tomatillo: falscher Präfix-Treffer.', 'fachwissen');
    $mkDoc('niveau-gehoben-teller', 'niveau', 'Gehoben am Teller: drei Texturen.');
    $mkDoc('niveau-gehoben-basis', 'niveau', 'Gehoben als Komponente: saubere Basis.');
    $mkDoc('niveau-klassisch-teller', 'niveau', 'Klassisch am Teller.');
    $mkDoc('kueche-suppen-tomate', 'kueche', 'Suppen-Küche: Tomatensuppe, Ansatz, Mixen.');
    $mkDoc('kueche-dessert-schoko', 'kueche', 'Dessert: Schokolade temperieren.');
    $mkDoc('produktion-kapazitat-a', 'produktion', 'Produktion A: 40 Portionen je Stunde.');
    $mkDoc('produktion-kapazitat-b', 'produktion', 'Produktion B: Kühlkette.');
    $mkDoc('fachwissen-basilikum-tomate', 'technik', 'Fachwissen Basilikum zu Tomate, spät zugeben.', 'fachwissen');
    $mkDoc('referenz-tomatensuppe-klassiker', 'referenzgericht', 'Referenz: klassische Tomatensuppe.', 'referenz');
    $mkDoc('regelwerk-basisrezepte-2', 'regelwerk', 'Basisrezepte §2: Verarbeitungs-Reduktion.', 'regel');
    $mkDoc('regelwerk-basisrezepte-6', 'regelwerk', 'Basisrezepte §6: Mengen/Yield.', 'regel');
    $mkDoc('regelwerk-verkaufsgerichte-1', 'regelwerk', 'VK §1: Teller-Aufbau.', 'regel');
    $mkDoc('trend-mit-frontmatter', 'trend', "---\nthema: Fermentation\nsub_thema: \"Koji\"\nrelevanz: hoch\ntags: [koji, 'miso', umami]\nquellen:\n  - Buch A\n  - \"Studie B\"\n---\n# Koji\nText.");
    $mkDoc('ablauf-anleitung', 'ablauf', 'Ablauf: erst A, dann B.', 'ablauf');

    // Pairing-Graph: basilikum ↔ tomate (Stufe 3), basilikum ↔ erdbeere (Stufe 2).
    $mkAnker = function (string $slug, string $display): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => $display,
            'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00',
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $basilikum = $mkAnker('basilikum', 'Basilikum');
    $tomate = $mkAnker('tomate', 'Tomate');
    $erdbeere = $mkAnker('erdbeere', 'Erdbeere');
    $mkAnker('neutral', 'Neutral');
    \Platform\FoodAlchemist\Tests\Support\Harmonie::ausFixture(['anchor_a_id' => $basilikum, 'anchor_b_id' => $tomate, 'type' => 'erprobt']);
    \Platform\FoodAlchemist\Tests\Support\Harmonie::ausFixture(['anchor_a_id' => $basilikum, 'anchor_b_id' => $erdbeere, 'type' => 'aroma']);
});

/** Alles, was contextFor() zurückgibt, ohne Laufzeit-Rauschen. */
function knowledgeContextErgebnis(array $ctx): array
{
    ksort($ctx);

    return $ctx;
}

it('contextFor: alle Kanäle in einem Feature (Concept, Achse, Cross-Cutting, Domain, Pairing, Grounding, Zutat, Niveau, discovery, always)', function () {
    $f = 'test.kc.voll';
    ($this->mkRouting)($f, 'concept', 'always', 2);
    ($this->mkRouting)($f, 'cross_cutting', 'always');
    ($this->mkRouting)($f, 'domain', 'discovery', 2);
    ($this->mkRouting)($f, 'pairing', 'discovery');
    ($this->mkRouting)($f, 'zutat', 'grounding', 4);
    ($this->mkRouting)($f, 'niveau', 'discovery');
    ($this->mkRouting)($f, 'kueche', 'discovery', 1);
    ($this->mkRouting)($f, 'produktion', 'always', 1);
    ($this->mkRouting)($f, 'trend', 'discovery');

    $ctx = app(KnowledgeContextService::class)->contextFor($this->rootTeam, $f,
        'Tomatensuppe mit Basilikum, klassischer Ansatz, Fonds', 'kreativ', ['tomate', 'basilikum', 'gibtsnicht'],
        ['niveau' => 'gehoben', 'rezept_typ' => 'gericht', 'occasion' => 'dinner', 'aroma_kueche' => 'thai', 'saison' => 'sommer']);

    knowledgeContextGolden('contextFor.voll', knowledgeContextErgebnis($ctx));
});

it('contextFor: Pairing-Doku-Grounding je Hauptzutat (eigene Routing-Zeile, Unique je Feature/Kategorie)', function () {
    ($this->mkRouting)('test.kc.grounding', 'pairing', 'grounding', 2);
    $svc = app(KnowledgeContextService::class);

    knowledgeContextGolden('contextFor.pairing_grounding', [
        'treffer' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'test.kc.grounding', 'Basilikum', null, ['basilikum', 'Tomate', ''])),
        'leer' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'test.kc.grounding', 'nichts', null, ['gibtsnicht'])),
    ]);
});

it('contextFor: Zutat-Grounding ohne Hauptzutaten (abgeleitete Leit-Tokens) und Aspekt nach Prompt-Key', function () {
    ($this->mkRouting)('recipe.steps', 'zutat', 'grounding', 8);
    ($this->mkRouting)('gp.suggest', 'zutat', 'grounding', 8);
    $svc = app(KnowledgeContextService::class);

    knowledgeContextGolden('contextFor.zutat_abgeleitet', [
        'recipe.steps' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'recipe.steps', 'Tomate geschmort', null)),
        'gp.suggest' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'gp.suggest', 'Tomate', null, ['tomate'])),
    ]);
});

it('contextFor: Alias-Rückfall vk.generator → ai_generate_recipe je Kategorie, Rezept-Priorität und Ausschlüsse', function () {
    ($this->mkRouting)('ai_generate_recipe', 'kueche', 'discovery', 2);
    ($this->mkRouting)('ai_generate_recipe', 'regelwerk', 'discovery', 2);
    ($this->mkRouting)('ai_generate_recipe', 'domain', 'discovery', 3);
    ($this->mkRouting)('vk.generator', 'domain', 'discovery', 1);          // eigene Zeile gewinnt für domain
    $svc = app(KnowledgeContextService::class);

    knowledgeContextGolden('contextFor.alias_vk', [
        'wirksame_routings' => $svc->wirksameRoutings('vk.generator')->map(fn ($r) => [$r->feature, $r->category, $r->mode, $r->max_docs])->values()->all(),
        'ohne_ausschluss' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'vk.generator', 'Tomatensuppe Ansatz Fonds', null)),
        'mit_ausschluss' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'vk.generator', 'Tomatensuppe Ansatz Fonds', null, [],
            ['_exclude_slugs' => ['kueche-suppen-tomate@v1', ' regelwerk-basisrezepte-2 ']])),
        'max_chars' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'vk.generator', 'Tomatensuppe Ansatz Fonds', null, [],
            ['_max_chars' => 60])),
        'scope' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, 'vk.generator', 'Tomatensuppe Ansatz Fonds', null, [],
            ['_knowledge_scope' => ['fonds-bruehen-ansatz@v1', 'graph:tomate', 'kueche-dessert-schoko']])),
    ]);
});

it('contextFor: Arten-Routing (fachwissen/referenz discovery) blendet art-Dossiers aus dem Kategorie-Pfad aus', function () {
    $f = 'test.kc.arten';
    ($this->mkRouting)($f, null, 'discovery', 3, 'fachwissen');
    ($this->mkRouting)($f, null, 'discovery', 2, 'referenz');
    ($this->mkRouting)($f, 'regelwerk', 'discovery', 2);
    ($this->mkRouting)($f, 'cross_cutting', 'always');
    $svc = app(KnowledgeContextService::class);

    knowledgeContextGolden('contextFor.arten', [
        'voll' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, $f, 'Tomatensuppe Basilikum Tomate', null, ['tomate'])),
        'nur_pflicht' => knowledgeContextErgebnis($svc->contextFor($this->rootTeam, $f, 'Tomatensuppe Basilikum', null, [], ['_required_only' => true])),
        'schmal_cross_cutting' => knowledgeContextErgebnis($svc->contextFor(null, 'test.kc.schmal', 'egal', null)),
    ]);
});

it('Routing, Budget und Pflichtmengen', function () {
    ($this->mkRouting)('test.kc.pflicht', 'concept', 'always', 1);
    ($this->mkRouting)('test.kc.pflicht', 'cross_cutting', 'always');
    ($this->mkRouting)('test.kc.pflicht', 'regelwerk', 'always');
    ($this->mkRouting)('test.kc.pflicht', 'produktion', 'always', 2);
    ($this->mkRouting)('test.kc.pflicht', 'kueche', 'discovery', 2);
    $svc = app(KnowledgeContextService::class);

    knowledgeContextGolden('routing_budget', [
        'routingFeatureFuer' => array_map(fn ($k) => KnowledgeContextService::routingFeatureFuer($k), ['recipe.generator', 'vk.generator', 'recipe.dichteklasse', 'concept.plan']),
        'crossCuttingSlugs' => ['standard' => $svc->crossCuttingSlugs('irgendwas'), 'schmal' => $svc->crossCuttingSlugs('test.kc.schmal')],
        'budgetFuer' => array_map(fn ($k) => $svc->budgetFuer($k), ['ai_generate_recipe', 'recipe.generator', 'vk.generator', 'test.kc.pflicht', 'concept.plan']),
        'pflichtZeichen' => $svc->pflichtZeichen('test.kc.pflicht'),
        'pflichtBudgetFuer' => $svc->pflichtBudgetFuer($this->rootTeam, 'test.kc.pflicht'),
        'proposeOptionen' => [
            KnowledgeContextService::proposeOptionen(['block' => 'X', 'files_used' => ['a@v1'], 'dropped_chars' => 7]),
            KnowledgeContextService::proposeOptionen([]),
        ],
        'regelwerkDossiersFuer' => [
            'recipe.generator' => $svc->regelwerkDossiersFuer($this->rootTeam, 'recipe.generator')->map(fn ($d) => (array) $d)->all(),
            'vk.review' => $svc->regelwerkDossiersFuer($this->rootTeam, 'vk.review', ['slug'])->pluck('slug')->all(),
            'unbekannt' => $svc->regelwerkDossiersFuer($this->rootTeam, 'gibt.es.nicht')->all(),
        ],
        'kanonTextFuer' => $svc->kanonTextFuer($this->rootTeam, 'recipe.generator'),
    ]);
});

it('Text-Helfer: tokenize, jaccard, truncate, extractPairingNames, frontmatterOf', function () {
    $svc = new KnowledgeContextService();
    $md = "# Tomate\nEinleitung **nicht**\n## Pairings\n### Klassisch\n- [[basilikum|Basilikum]] und **Mozzarella**\n### Modern\n- [[erdbeere]] · **Rhabarber**\n"
        ."## Verbund\n- [[oliven|Oliven]]\n## Notizen\n- [[spaet|Spät]]";

    knowledgeContextGolden('text', [
        'tokenize' => $svc->tokenize('Äpfel-Schmorgurke, Weißkraut & Öl; ab xy Größe'),
        'jaccard' => [$svc->jaccard(['a', 'b', 'c'], ['b', 'c', 'd']), $svc->jaccard([], ['a']), $svc->jaccard(['a'], ['a'])],
        'truncate' => [$svc->truncate('kurz', 10), $svc->truncate('ein langer Text über Tomaten', 8)],
        'extractPairingNames' => [
            'alle' => $svc->extractPairingNames($md),
            'klassisch' => $svc->extractPairingNames($md, ['Klassisch']),
            'modern_verbund' => $svc->extractPairingNames($md, ['Modern', 'Verbund']),
            'ohne_region' => $svc->extractPairingNames('keine Pairings hier'),
        ],
        'frontmatterOf' => [
            'mit' => $svc->frontmatterOf((string) DB::table('foodalchemist_knowledge_documents')->where('slug', 'trend-mit-frontmatter')->value('content_md')),
            'ohne' => $svc->frontmatterOf("# Nur Text\n---\nkein: frontmatter"),
            'bom' => $svc->frontmatterOf("\u{FEFF}---\ntags: einzeln\n---\n"),
        ],
    ]);
});

it('MCP-Lesewege: searchDocuments, listDocuments, getDocument, sichtbare/mcp-lesbare Dokumente', function () {
    $svc = app(KnowledgeContextService::class);
    $doc = $svc->getDocument($this->rootTeam, 'trend-mit-frontmatter');
    $liste = $svc->listDocuments($this->rootTeam, null, 2, 5);

    knowledgeContextGolden('mcp', [
        'searchDocuments' => array_map(fn ($h) => array_diff_key($h, ['id' => 1]), $svc->searchDocuments($this->rootTeam, 'tomate suppe', null, 5)),
        'searchDocuments_kategorie_inaktiv' => array_map(fn ($h) => array_diff_key($h, ['id' => 1]), $svc->searchDocuments($this->rootTeam, 'concept', 'concept', 10, true)),
        'listDocuments' => $liste,
        'listDocuments_kategorie_ohne_fm' => $svc->listDocuments($this->rootTeam, 'cross_cutting', 0, 500, false),
        'getDocument' => $doc === null ? null : array_diff_key((array) $doc, ['id' => 1]),
        'getDocument_inaktiv' => $svc->getDocument($this->rootTeam, 'concept-zzz-inaktiv'),
        'sichtbareDokumente' => $svc->sichtbareDokumente($this->rootTeam)->orderBy('slug')->pluck('slug')->all(),
        'mcpLesbareDokumente' => $svc->mcpLesbareDokumente($this->rootTeam)->orderBy('slug')->pluck('slug')->all(),
        'sichtbareDokumente_ohne_team' => $svc->sichtbareDokumente(null)->count(),
    ]);
});
