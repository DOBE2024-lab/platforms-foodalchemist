<?php

/**
 * Food Alchemist Service Provider
 * 
 * Dieser Service Provider ist das Herzstück jedes Platform-Moduls.
 * 
 * WICHTIG FÜR LLMs:
 * - Dieser Service Provider folgt dem exakten Muster von HCM und Planner
 * - Alle wichtigen Schritte sind kommentiert
 * - Config wird in register() geladen (Laravel Best Practice)
 * - Modul-Registrierung erfolgt in boot()
 * 
 * ANPASSUNGEN FÜR NEUES MODUL:
 * 1. Ersetze "FoodAlchemist" durch deinen Modul-Namen (PascalCase)
 * 2. Ersetze "foodalchemist" durch deinen Modul-Namen (kebab-case)
 * 3. Passe Namespaces an
 * 4. Füge Commands/Tools hinzu falls nötig
 * 
 * @see Platform\Core\PlatformCore für Modul-Registrierung
 * @see Platform\Core\Routing\ModuleRouter für Route-Registrierung
 */

namespace Platform\FoodAlchemist;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FoodAlchemistServiceProvider extends ServiceProvider
{
    /**
     * Register Services
     * 
     * Wird VOR boot() aufgerufen.
     * Hier sollten nur leichte Registrierungen erfolgen.
     * 
     * LARAVEL BEST PRACTICE:
     * - Config sollte hier geladen werden (mergeConfigFrom)
     * - Commands können hier registriert werden
     */
    public function register(): void
    {
        $this->app->scoped(\Platform\FoodAlchemist\Services\Knowledge\KnowledgeRunContext::class);
        // Spec 84 §3.3: Merker des Team-Kontexts je Request/Job (scoped = Laravel leert ihn zwischen Jobs)
        $this->app->scoped(\Platform\FoodAlchemist\Support\FaTeam::class);
        $this->app->scoped(\Platform\FoodAlchemist\Services\Ai\KiExternModus::class);   // #1002: externer KI-Weg je Request/Job
        $this->app->scoped(\Platform\FoodAlchemist\Support\AngebotFestpreise::class);   // eingefrorenes Angebot: Festpreise je Request
        $this->app->scoped(\Platform\FoodAlchemist\Services\Ai\KiKlassenEinstellung::class);   // Spec 85: KI-Klasse je Schritt, je Request gelesen
        // Spec 84 §4.4 (E3): aktive Support-Freigabe je Request/Job gemerkt (FaRechte::rolle fragt die Sperre sehr oft)
        $this->app->scoped(\Platform\FoodAlchemist\Services\SupportFreigabeService::class);
        // Spec 77c: Team-Namen der Standort-Spalte je Request merken
        $this->app->scoped(\Platform\FoodAlchemist\Services\StandortService::class);
        /**
         * Config laden
         * 
         * mergeConfigFrom lädt die Config aus dem Package-Verzeichnis
         * und merged sie mit der Config aus config/ (falls vorhanden).
         * 
         * WICHTIG: Muss in register() sein, nicht in boot()!
         */
        $this->mergeConfigFrom(__DIR__.'/../config/foodalchemist.php', 'foodalchemist');
        
        /**
         * Commands registrieren
         */
        if ($this->app->runningInConsole()) {
            $this->commands(require __DIR__ . '/Console/_liste.php');   // 98 · 1.2d: eine Zeile je Command, alphabetisch

            $this->planeLaeufe();
        }
    }

    /**
     * Den Qualitäts-Lauf täglich einplanen — **im Modul**, nicht im Host-Kernel.
     *
     * Der Command trug seit seiner Entstehung den Kommentar „Registrierung der Cron-Frequenz
     * ist Host-/Deploy-Sache (Console-Kernel der office.bhg-App)". Dort ist es nie passiert.
     * Die Folge war ein blinder Fleck, der wie ein fehlendes Feature aussah: die 20+ Signal-
     * Typen für Rezepte/Konzepte/Foodbooks und die komplette Zeitreihe existierten im Code,
     * aber nie in den Daten — eine Ampel, die nur leuchtet, wenn jemand eine Shell öffnet.
     *
     * Ein Modul, dessen Kernfunktion von einer Registrierung in einem fremden Repo abhängt,
     * hat diese Funktion nicht. Darum hängt der Plan jetzt hier: mitdeployt, mitversioniert,
     * ohne Zutun von außen. Der Host behält die Hoheit — er kann den Eintrag über
     * `foodalchemist.scheduler.enabled` abschalten.
     *
     * Bewusst NUR der Detektor. `recipe-findings` bleibt manuell: er ruft das Modell pro
     * Rezept, und ein nächtlicher Job, der ungefragt Provider-Geld ausgibt, ist keine
     * Bequemlichkeit, sondern eine unbemerkte Rechnung.
     *
     * 03:20 Uhr: nach dem DB-Snapshot (23:00) und weit vor dem Arbeitstag — der Lauf ist die
     * teuerste lesende Operation des Moduls und soll nicht neben der Nutzung liegen.
     */
    private function planeLaeufe(): void
    {
        if (! config('foodalchemist.scheduler.enabled', true)) {
            return;
        }

        // `booted`, weil der Scheduler beim Registrieren des Providers noch nicht steht. Der Plan selbst: src/Console/_zeitplan.php (98 · 1.2d).
        $this->app->booted(function () {
            (require __DIR__ . '/Console/_zeitplan.php')($this->app->make(\Illuminate\Console\Scheduling\Schedule::class));
        });
    }

    /**
     * Boot Services
     * 
     * Wird NACH register() aufgerufen.
     * Hier erfolgt die eigentliche Modul-Registrierung.
     * 
     * REIHENFOLGE IST WICHTIG:
     * 1. Config prüfen (bereits in register() geladen)
     * 2. Modul bei PlatformCore registrieren
     * 3. Routes laden (nur wenn Modul registriert)
     * 4. Migrationen, Views, Livewire registrieren
     */
    public function boot(): void
    {
        // CQ (Folge aus 2.5b): neues Wurzel-Team → Grundbestand der Planungs-Vokabulare. Darf das Anlegen nie scheitern lassen.
        \Platform\Core\Models\Team::created(fn (\Platform\Core\Models\Team $team) => \Platform\FoodAlchemist\Support\Planung\VokabularSeed::fuerNeuesTeam($team));

        // Spec 84 §3.3 · Team-Transport in Jobs an EINER Stelle: jeder Job trägt das Team, das beim Versand galt — egal ob aus
        // Livewire, MCP oder FaTeam::imTeam. Läuft auf der Kopie, die in den Payload geht (der laufende Request bleibt unberührt).
        \Illuminate\Support\Facades\Context::dehydrating(function (\Illuminate\Log\Context\Repository $context) {
            if (! $context->hasHidden(\Platform\FoodAlchemist\Support\FaTeam::SCHLUESSEL)) {
                $context->addHidden(\Platform\FoodAlchemist\Support\FaTeam::SCHLUESSEL, \Platform\FoodAlchemist\Support\FaTeam::aktuellId());
            }
        });

        // Spec 65 · Bearbeitungssperre: serverseitige Durchsetzung für alle Editoren mit MitBearbeitungssperre.
        // Jede Methode außerhalb von sperrFreieMethoden() läuft nur, wenn die aufrufende Person die Sperre hält
        // (Klick auf „Bearbeiten"); sonst Abweisung mit Hinweis statt stillem Schreiben.
        // Spec 77b · Bereich der Komponente: gesperrter Bereich = keine Aktion (Seitenaufruf sperrt die Middleware).
        \Livewire\on('call', function ($component, $method, $params, $context, $returnEarly) {
            $bereich = \Platform\FoodAlchemist\Support\FaBereiche::fuerKomponente($component);
            $user = \Illuminate\Support\Facades\Auth::user();
            // Spec 82 §13 C3: Vollsperre eines beendeten Mandanten — keine Aktion, auch nicht für Plattform-Admins
            if ($user instanceof \Platform\Core\Models\User && ! \Platform\FoodAlchemist\Support\TeamSperre::komponenteErlaubt($component)
                && \Platform\FoodAlchemist\Support\TeamSperre::istGesperrt($user->currentTeamRelation)) {
                $component->dispatch('fa-saved', message: \Platform\FoodAlchemist\Support\TeamSperre::MELDUNG, type: 'error');
                $returnEarly(null);

                return;
            }
            // Spec 82 §13 G: Plattform-Admin ohne echte Mitgliedschaft — keine Aktion auf Inhalten eines Kunden-Teams
            if ($user instanceof \Platform\Core\Models\User && ! \Platform\FoodAlchemist\Support\InhaltsSperre::komponenteErlaubt($component)
                && \Platform\FoodAlchemist\Support\InhaltsSperre::istGesperrt($user, $user->currentTeamRelation)) {
                $component->dispatch('fa-saved', message: \Platform\FoodAlchemist\Support\InhaltsSperre::MELDUNG, type: 'error');
                $returnEarly(null);

                return;
            }
            // Spec 84 §4.4 (E3): Aktion im Support-Zugang → Protokoll (wer, wann, welche Komponente/Methode)
            // Polls ($refresh …) und der Sperr-Herzschlag sind kein Zugriff — sonst flutet ein offenes Fenster das Protokoll
            if ($user instanceof \Platform\Core\Models\User && ! str_starts_with((string) $method, '$') && $method !== 'sperreHerzschlag'
                && ($sf = \Platform\FoodAlchemist\Support\InhaltsSperre::perSupportZugang($user, $user->currentTeamRelation)) !== null) {
                app(\Platform\FoodAlchemist\Services\SupportFreigabeService::class)->protokolliereZugriff($sf, $user, $user->currentTeamRelation, 'livewire:'.$component::class.'::'.$method);
            }
            if ($bereich !== null && $user instanceof \Platform\Core\Models\User && ($team = $user->currentTeamRelation) !== null
                && ! app(\Platform\FoodAlchemist\Services\FaRechte::class)->darfBereich($user, $team, $bereich)) {
                $component->dispatch('fa-saved', message: 'Dieser Bereich ist für dich bzw. dein Team nicht freigeschaltet.', type: 'error');
                $returnEarly(null);
            }
        });
        // Spec 77d · Inhalt einer freigegebenen Ausgabe geändert → Hüllen der Standorte neu rechnen (nur wenn es Freigaben gibt)
        foreach ([
            \Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot::class, \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistPaketGericht::class, \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::class, \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag::class, \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition::class,
        ] as $inhaltsModel) {
            $inhaltsModel::saved(fn () => app(\Platform\FoodAlchemist\Services\InhaltsFreigabeService::class)->vormerken());
            $inhaltsModel::deleted(fn () => app(\Platform\FoodAlchemist\Services\InhaltsFreigabeService::class)->vormerken());
        }
        // Spec 77b · Seiten je Bereich (Route → Bereich), für alle Web-Routen; Nicht-FA-Routen laufen durch.
        $this->app['router']->pushMiddlewareToGroup('web', \Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware::class);

        \Livewire\on('call', function ($component, $method, $params, $context, $returnEarly) {
            if (! method_exists($component, 'sperreErlaubt') || $component->sperreErlaubt((string) $method)) {
                return;
            }
            $component->sperreAbgewiesen((string) $method);
            $returnEarly(null);
        });

        // #1003 Handy-Cookie setzt das Gerät per JavaScript (HandyLesemodus::JS_MODUS) → nicht verschlüsselt lesen.
        \Illuminate\Cookie\Middleware\EncryptCookies::except(\Platform\FoodAlchemist\Support\HandyLesemodus::COOKIE);

        // #1003 Handy-Lesemodus serverseitig: Lese-Bereiche nehmen vom Handy nur Lese- und freigeschaltete Methoden an.
        \Livewire\on('call', function ($component, $method, $params, $context, $returnEarly) {
            if (! \Platform\FoodAlchemist\Support\HandyLesemodus::abgewiesen($component, (string) $method, (array) $params)) {
                return;
            }
            $component->dispatch('fa-saved', message: \Platform\FoodAlchemist\Support\HandyLesemodus::MELDUNG, type: 'error');
            $returnEarly(null);
        });

        // Upload-Fehler, die keine FA-Regel erreicht (Livewires Vorab-Prüfung), deutsch statt »validation.max.file« (Host ohne lang/de).
        \Livewire\before('exception', fn ($component, $fehler) => \Platform\FoodAlchemist\Support\DateiUpload::rohschluesselUebersetzen($component, $fehler));

        // GP-Regelwerk v3.7.1 §5 Ausnahme 3: Marken-Kürzel einmal je Anfrage laden (Karte im Dienst)
        $this->app->scoped(\Platform\FoodAlchemist\Services\Marken\MarkeAusArtikel::class);

        // Spec 79: Google Trends für den Trendradar — produktiv über DataForSEO (Integrations-Modul)
        $this->app->bind(\Platform\FoodAlchemist\Services\Stammdaten\NaehrwertPruefer::class, \Platform\FoodAlchemist\Services\Stammdaten\NaehrwertPruefServiceAdapter::class);   // Stammdaten-Import: die eine Nährwert-Prüfung (#638)
        $this->app->bind(\Platform\FoodAlchemist\Services\Trends\GoogleTrendsQuelle::class, \Platform\FoodAlchemist\Services\Trends\DataForSeoGoogleTrends::class);
        $this->app->bind(\Platform\FoodAlchemist\Services\Speiseplan\SpeiseplanDokumentQuelle::class, \Platform\FoodAlchemist\Services\SpeiseplanService::class);
        $this->app->bind(\Platform\FoodAlchemist\Services\Foodbook\FoodbookDokumentQuelle::class, \Platform\FoodAlchemist\Services\FoodbookService::class);   // 98 · 3.3 S4-0k: Präsentation → Foodbook-Dokument
        $this->app->bind(\Platform\FoodAlchemist\Services\Foodbook\FoodbookAggregatQuelle::class, \Platform\FoodAlchemist\Services\FoodbookService::class);   // 98 · 3.3 S4-0k: Soll-Ist → Kapitel-Aggregat/Ampel/Gesamt   // 98 · 3.3 S3-0: Präsentation → Speiseplan-Aushang ohne Kreis (Muster K3)
        // Web-Recherche zu Lieferantenartikeln (Spec 85 I5-8): Websuche über den Core-Contract (include_web_search, Core #935)
        $this->app->bind(\Platform\FoodAlchemist\Services\Websuche\WebSucheTransport::class, \Platform\FoodAlchemist\Services\Websuche\CoreWebSucheTransport::class);
        // Produktdaten-Quellen fuer den Produktpass-Panel: die Websuche haengt als Quelle websuche im Tag (Knopf Im Web recherchieren)
        $this->app->tag([\Platform\FoodAlchemist\Services\ArtikelWebRecherche::class], 'produktdaten.quellen');

        // M7-10 / D8: STT-Fassade — Binding-Tausch genügt für einen späteren Core-Contract
        // STT-Treiber: 'auto' nimmt den Dienst, für den ein Zugang existiert. Der
        // frühere Default 'fake' hat auf demo jeden gesprochenen Befehl durch den
        // Fixtext ersetzt — fehlerfrei und trotzdem falsch. Ein Fallback auf Testdaten
        // darf nie der Normalfall sein.
        $this->app->bind(\Platform\FoodAlchemist\Services\Stt\SttServiceContract::class, function () {
            $provider = (string) config('foodalchemist.stt.provider', 'auto');
            if ($provider === 'auto') {
                $provider = match (true) {
                    (string) config('services.openai.api_key') !== '' => 'openai',
                    (string) config('foodalchemist.stt.key') !== '' => 'assemblyai',
                    default => 'fake',
                };
            }
            // Spec 53/D: Fake ist ausserhalb von testing/local nur mit dem expliziten Flag erlaubt.
            // Vorher landete eine demo-/Produktions-Instanz ohne jeden Zugang HIER auf Fake und
            // ersetzte jeden Sprachbefehl stumm durch den Fixtext — fehlerfrei und trotzdem falsch.
            if ($provider === 'fake' && ! $this->app->environment(['testing', 'local'])
                && ! config('foodalchemist.stt.allow_fake', false)) {
                $provider = 'none';
            }

            return match ($provider) {
                'openai' => new \Platform\FoodAlchemist\Services\Stt\OpenAiSttService(),
                'assemblyai' => new \Platform\FoodAlchemist\Services\Stt\AssemblyAiSttService(),
                'fake' => new \Platform\FoodAlchemist\Services\Stt\FakeSttService(),
                default => new \Platform\FoodAlchemist\Services\Stt\UnkonfiguriertSttService(),
            };
        });

        // Spec 53 / Paket F (3): TTS-Fassade — gleiches Auto/Fake-Prinzip wie STT oben.
        $this->app->bind(\Platform\FoodAlchemist\Services\Tts\TtsServiceContract::class, function () {
            $provider = (string) config('foodalchemist.tts.provider', 'auto');
            if ($provider === 'auto') {
                $provider = match (true) {
                    (string) config('services.openai.api_key') !== '' => 'openai',
                    default => 'fake',
                };
            }
            if ($provider === 'fake' && ! $this->app->environment(['testing', 'local'])
                && ! config('foodalchemist.tts.allow_fake', false)) {
                $provider = 'none';
            }

            return match ($provider) {
                'openai' => new \Platform\FoodAlchemist\Services\Tts\OpenAiTtsService(),
                'fake' => new \Platform\FoodAlchemist\Services\Tts\FakeTtsService(),
                default => new \Platform\FoodAlchemist\Services\Tts\UnkonfiguriertTtsService(),
            };
        });

        // E1 (#507): Embedding-Observer — halten die GP-/Rezept-Recall-Vektoren bei
        // interaktiven Einzeledits synchron (Bulk = foodalchemist:embed). Unbedingt
        // registriert (nicht table-guarded — der Guard liefe zur Boot-Zeit, bevor
        // Migrationen durch sind). Ungefährlich: die Observer feuern nur auf Model-
        // Events (nie während Migrationen), und queueGp/deleteGp no-oppen ohne Provider.
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::observe(\Platform\FoodAlchemist\Observers\GpEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::observe(\Platform\FoodAlchemist\Observers\GpAromaObserver::class);   // 27.10.2026: Anker + Profil auf allen Anlagewegen
        // Spec 67: neuer/klassifizierter GP → Stellplatz-Vorschlag im Standardlager des Teams
        \Platform\FoodAlchemist\Models\FoodAlchemistGp::observe(\Platform\FoodAlchemist\Observers\GpLagerObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::observe(\Platform\FoodAlchemist\Observers\RecipeEmbeddingObserver::class);

        // Spec 15 §5a/§5b: die kleinen Geschwister-Pools (Lieferant/Konzept/Foodbook/Lab-Note).
        \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::observe(\Platform\FoodAlchemist\Observers\SupplierEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistConcept::observe(\Platform\FoodAlchemist\Observers\ConceptEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::observe(\Platform\FoodAlchemist\Observers\FoodbookEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistLabNote::observe(\Platform\FoodAlchemist\Observers\LabNoteEmbeddingObserver::class);

        // RAG-Autoindex A3: Lieferartikel-Einzeledits synchron halten. Der Katalog-Bulk-Import
        // umgeht Eloquent → bleibt der explizite Backfill (foodalchemist:embed --pool=la).
        \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::observe(\Platform\FoodAlchemist\Observers\SupplierItemEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::observe(\Platform\FoodAlchemist\Observers\ItemAllergenKonfidenzObserver::class);   // Allergen-Konfidenz/Review sofort nachziehen

        // Ausbau (b): Ausgabe-/Container-Pools (Speisekarte/Angebot/Paket/Format) — Einzeledits
        // synchron; Bulk je Pool via foodalchemist:embed --pool=speisekarten|angebote|pakete|formate.
        \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::observe(\Platform\FoodAlchemist\Observers\SpeisekarteEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::observe(\Platform\FoodAlchemist\Observers\AngebotEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistPaket::observe(\Platform\FoodAlchemist\Observers\PaketEmbeddingObserver::class);
        \Platform\FoodAlchemist\Models\FoodAlchemistFormat::observe(\Platform\FoodAlchemist\Observers\FormatEmbeddingObserver::class);

        // Embedding-Store-Routing (Runbook 34_Qdrant): FA deklariert selbst, in welchen
        // Store seine Pools gehen — Cores EmbeddingStoreRegistry bleibt entity-agnostisch
        // (der bevorzugte, lose gekoppelte Weg statt zentraler config('embeddings.routing')).
        // INVARIANTE: alle neun foodalchemist_*-Pools teilen sich EINEN Store. candidates()
        // in SemanticRetrievalService übergibt gemischte entity_type-Arrays (z. B. [GP, RECIPE]);
        // search() routet am ersten Typ → nur bei gemeinsamem Store liefern Mixed-Type-Suchen
        // vollständig. Store-Wechsel deshalb immer für ALLE neun gleichzeitig.
        // Kill-Switch/Rollback: FOODALCHEMIST_EMBEDDING_STORE — Default 'mysql' = No-op
        // (Verhalten wie bisher), Flip auf 'qdrant' beim Cutover per ENV (kein Deploy nötig).
        // Geschützt wie die Tool-Registrierung: das Registry-Binding kann auf älteren
        // Core-Versionen / während der Initial-Migration fehlen → nie den Boot brechen.
        if (
            class_exists(\Platform\Core\Services\EmbeddingStoreRegistry::class)
            && $this->app->bound(\Platform\Core\Services\EmbeddingStoreRegistry::class)
        ) {
            try {
                $embeddingStore = (string) config('foodalchemist.embedding_store', 'mysql');
                $embeddingRegistry = $this->app->make(\Platform\Core\Services\EmbeddingStoreRegistry::class);
                foreach ([
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_GP,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_RECIPE,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_SUPPLIER,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_CONCEPT,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_FOODBOOK,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE,
                    \Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService::ENTITY_TYPE_SUPPLIER_ITEM,
                    \Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::ENTITY_TYPE,
                    \Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::ENTITY_TYPE_ANKER,
                ] as $embeddingEntityType) {
                    $embeddingRegistry->route($embeddingEntityType, $embeddingStore);
                }
            } catch (\Throwable $e) {
                // Ohne Registrierung greift Cores Default-Store (config('embeddings.store')).
            }
        }

        // Roadmap Planung-Leitstelle · Et.8 »Worker-Präsenz«: jeder lebende Queue-Worker stempelt einen
        // Herzschlag ({@see \Platform\FoodAlchemist\Services\WorkerHealthService}). `Looping` feuert bei
        // JEDER Worker-Schleife — auch im Leerlauf — und ist damit die ehrliche „ein Worker ist da"-Quelle;
        // `JobProcessing` hält busy-Phasen frisch. Der Stempel ist gedrosselt (10 s) und fail-soft, die
        // Signale sind plattformweit (FA-Jobs teilen die Default-Queue → jeder Worker, der sie leert, zählt).
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\Looping::class,
            fn () => $this->app->make(\Platform\FoodAlchemist\Services\WorkerHealthService::class)->heartbeat(),
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            fn () => $this->app->make(\Platform\FoodAlchemist\Services\WorkerHealthService::class)->heartbeat(),
        );

        // Spec 82 §12 A3: Der Opt-in-Stand (StammPaket::$cache, static) darf Request-/Job-Grenzen nicht überleben —
        // sonst sehen langlebige Prozesse (queue:work, Octane) einen Opt-in, der anderswo geändert wurde, erst nach Neustart.
        // Dasselbe gilt für den Sperr-Merker eines beendeten Mandanten (TeamSperre::$cache, Spec 82 §13 C3): Eine Sperre oder
        // Entsperrung in einem anderen Prozess muss ein Queue-Worker schon beim nächsten Job sehen, nicht erst nach einem Neustart.
        \Illuminate\Support\Facades\Event::listen(
            [\Illuminate\Queue\Events\JobProcessing::class, \Illuminate\Queue\Events\JobProcessed::class, \Illuminate\Queue\Events\JobFailed::class],
            function () {
                \Platform\FoodAlchemist\Support\StammPaket::flush();
                \Platform\FoodAlchemist\Support\TeamSperre::flush();
                app(\Platform\FoodAlchemist\Services\ConceptPreisService::class)->vergessen();   // Listen-Merker der Fassungs-Pfad-Prüfung (PR 5)
            },
        );
        // CQ 3.4: Rückkanäle Rezept → Concepter/Foodbook als synchrone Events (sofort, kein Queue; Reihenfolge und Exceptions wie
        // vorher beim Direktaufruf). Fehlt hier ein Eintrag, steht die Preisweitergabe still — Spec98AbhaengigkeitsRichtungTest prüft das.
        \Illuminate\Support\Facades\Event::listen(
            \Platform\FoodAlchemist\Events\RezeptKostenGeaendert::class,
            fn ($e) => $this->app->make(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeRecipes($e->recipeIds),
        );
        \Illuminate\Support\Facades\Event::listen(
            \Platform\FoodAlchemist\Events\TeamKostenGeaendert::class,
            fn ($e) => $this->app->make(\Platform\FoodAlchemist\Services\PricingCascadeService::class)->recomputeTeam($e->team),
        );
        \Illuminate\Support\Facades\Event::listen(   // entfällt in 2.6c mit PaketService
            \Platform\FoodAlchemist\Events\GerichtePreisVeraltet::class,
            function ($e) {
                $pakete = $this->app->make(\Platform\FoodAlchemist\Services\PaketService::class);
                foreach ($e->recipeIds as $rid) {
                    $pakete->markStaleForRecipe((int) $rid);
                }
            },
        );
        // CQ 3.4: Folge eines abgeschlossenen Kaskaden-Steps (Wording-Pass, Kohäsions-Score) — synchron, sofort, kein Queue
        \Illuminate\Support\Facades\Event::listen(
            \Platform\FoodAlchemist\Events\KaskadenSchrittAbgeschlossen::class,
            fn ($e) => $this->app->make(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)->nachSchrittAbschluss($e),
        );
        // Spec 84 E-1: langsame Abfragen messen (aus, solange foodalchemist.leistung.langsam_ms null ist)
        \Platform\FoodAlchemist\Support\LangsameAbfragen::registrieren($this->app);
        \Platform\FoodAlchemist\Support\FremdSchreiben::registrieren($this->app);   // Spec 84 B4: Kanal fa-fremdschreiben

        $this->app->terminating(function () {
            \Platform\FoodAlchemist\Support\StammPaket::flush();
            \Platform\FoodAlchemist\Support\TeamSperre::flush();
            app(\Platform\FoodAlchemist\Services\ConceptPreisService::class)->vergessen();
        });

        /**
         * SCHRITT 1: Modul-Registrierung prüfen
         * 
         * Prüft ob:
         * - Config vorhanden ist
         * - modules-Tabelle existiert (für Datenbank-Registrierung)
         * 
         * Nur wenn beide Bedingungen erfüllt, wird das Modul registriert.
         */
        if (
            config()->has('foodalchemist.routing') &&
            config()->has('foodalchemist.navigation') &&
            PlatformCore::modulesTableExists()
        ) {
            /**
             * Modul bei PlatformCore registrieren
             * 
             * Dies registriert das Modul in:
             * - Der Modul-Registry (für Navigation, Sidebar)
             * - Der Datenbank (modules-Tabelle)
             * 
             * Die Config wird automatisch aus config/foodalchemist.php geladen.
             */
            PlatformCore::registerModule([
                'key'        => 'foodalchemist', // Eindeutiger Schlüssel
                'title'      => 'Food Alchemist', // Anzeige-Name
                'routing'    => config('foodalchemist.routing'),
                'guard'      => config('foodalchemist.guard'),
                'navigation' => config('foodalchemist.navigation'),
                'sidebar'    => config('foodalchemist.sidebar'),
            ]);
        }

        /**
         * SCHRITT 2: Routes laden
         * 
         * Routes werden nur geladen, wenn das Modul erfolgreich registriert wurde.
         * 
         * ModuleRouter::group() erstellt automatisch:
         * - Route-Prefix (aus Config)
         * - Middleware (web, auth, etc.)
         * - Domain-Handling (für Subdomain-Modus)
         */
        if (PlatformCore::getModule('foodalchemist')) {
            /**
             * Web-Routes (authentifiziert)
             * 
             * Standard: requireAuth = true
             * Für öffentliche Routes: requireAuth = false
             */
            ModuleRouter::group('foodalchemist', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
            
            /**
             * API-Routes (optional)
             * 
             * Falls dein Modul API-Endpoints hat:
             * 
             * ModuleRouter::apiGroup('foodalchemist', function () {
             *     $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
             * });
             */
        }

        /**
         * SCHRITT 2b: Öffentliche Routes (kein Auth) — Pairing-Netz-Frontend-Bundle
         *
         * Analog platform-core's _platform/assets/{file}: statische JS-Bundles
         * ohne Team-/Auth-Kontext, daher NICHT über ModuleRouter::group (das
         * hängt 'auth' + Tenancy-Middleware an), sondern direkt registriert.
         */
        \Illuminate\Support\Facades\Route::domain(parse_url(config('app.url'), PHP_URL_HOST))
            ->middleware(['web'])
            ->group(__DIR__.'/../routes/public.php');

        // Pairing-Netz-Bundle-Hash fürs Cache-Busting (analog CoreServiceProvider Tiptap/Workshop/Echo).
        $faManifestPath = __DIR__.'/../resources/dist/manifest.json';
        if (file_exists($faManifestPath)) {
            $faManifest = json_decode(file_get_contents($faManifestPath), true) ?? [];
            config(['platform.fa_pairing_netz_hash' => $faManifest['foodalchemist-pairing-netz.iife.js'] ?? '0']);
            // Spec 53 / Paket D: gemeinsamer Voice-Recorder (Sprachbefehl + Diktat), analog zum Pairing-Netz-Bundle.
            config(['platform.fa_voice_recorder_hash' => $faManifest['foodalchemist-voice-recorder.iife.js'] ?? '0']);
        }

        /**
         * SCHRITT 3: Migrationen laden
         * 
         * Lädt alle Migrationen aus database/migrations/
         * Wird automatisch bei `php artisan migrate` ausgeführt.
         */
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        /**
         * SCHRITT 4: Config veröffentlichen
         * 
         * Ermöglicht es, die Config in config/foodalchemist.php zu überschreiben.
         * 
         * Publizieren mit:
         * php artisan vendor:publish --tag=config --provider="Platform\FoodAlchemist\FoodAlchemistServiceProvider"
         * 
         * WICHTIG: mergeConfigFrom funktioniert auch OHNE Publizierung!
         */
        $this->publishes([
            __DIR__.'/../config/foodalchemist.php' => config_path('foodalchemist.php'),
        ], 'config');

        /**
         * SCHRITT 5: Views laden
         * 
         * Registriert Views unter dem Namespace 'foodalchemist'
         * 
         * Verwendung in Views:
         * @return view('foodalchemist::livewire.dashboard')
         */
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'foodalchemist');

        // fa-pass Welle 0: Bausteinbibliothek <x-fa::…> (resources/views/components/fa).
        // EINE Quelle für Knöpfe, Felder, Chips, Status, Kennzahlen, Allergene … — Doku: /foodalchemist/_ui
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(__DIR__ . '/../resources/views/components/fa', 'fa');
        
        /**
         * SCHRITT 6: Livewire Components registrieren
         * 
         * Registriert alle Livewire-Komponenten automatisch.
         * 
         * Pattern:
         * - Datei: src/Livewire/Dashboard.php
         * - Alias: foodalchemist.dashboard
         * 
         * Verwendung:
         * <livewire:foodalchemist.dashboard />
         */
        $this->registerLivewireComponents();

        // Spec 94: Konfigurator-Anfrage — 5 je Minute je IP und 20 je Stunde je Link.
        \Illuminate\Support\Facades\RateLimiter::for('fa-konfigurator-anfrage', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('ip|' . $r->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perHour(20)->by('link|' . sha1((string) $r->route('token'))),
        ]);
        // Lieferanten-Link (2b): Rate-Limit je Token + IP, austauschbarer Upload-Scanner, Benachrichtigungstyp „neuer Upload“.
        \Illuminate\Support\Facades\RateLimiter::for('fa-lieferanten-upload', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinutes(
            max(1, (int) config('foodalchemist.lieferanten_link.upload_fenster_minuten', 10)), max(1, (int) config('foodalchemist.lieferanten_link.upload_versuche', 10)),
        )->by(sha1((string) $r->route('token')) . '|' . $r->ip()));
        $this->app->bind(\Platform\FoodAlchemist\Support\UploadScanner::class, fn () => ($k = config('foodalchemist.lieferanten_link.scanner')) && is_a($k, \Platform\FoodAlchemist\Support\UploadScanner::class, true)
            ? $this->app->make($k) : new \Platform\FoodAlchemist\Support\KeinUploadScan);
        \Platform\FoodAlchemist\Services\LieferantenUploadBenachrichtigung::registriere();

        // M8-02: generische Modul-Policy für die Kern-Models (view = Team-Kette,
        // update/delete = Curate-Gate M1-08 — dieselben Regel-Stellen wie die Services)
        foreach ([
            \Platform\FoodAlchemist\Models\FoodAlchemistGp::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipe::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplier::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistDishClass::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCustomerName::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistRecipeRegeneration::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::class,                 // M11-10
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::class,
            \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock::class,
        ] as $modelClass) {
            \Illuminate\Support\Facades\Gate::policy($modelClass, \Platform\FoodAlchemist\Policies\FoodAlchemistPolicy::class);
        }

        // M8-01: Modul-Tools (ToolContract) — idempotent in die Core-Registry;
        // afterResolving, damit die Registrierung auch greift, wenn der MCP-
        // Server die Registry erst später aufbaut (Auto-Discovery-Pfad variiert)
        if (class_exists(\Platform\Core\Tools\ToolRegistry::class)) {
            $toolHook = fn ($registry) => \Platform\FoodAlchemist\Support\ToolListe::registriere($registry);
            // Singleton ggf. schon resolved (Core-Boot) → sofort; sonst beim ersten make
            if ($this->app->resolved(\Platform\Core\Tools\ToolRegistry::class)) {
                $toolHook($this->app->make(\Platform\Core\Tools\ToolRegistry::class));
            } else {
                $this->app->afterResolving(\Platform\Core\Tools\ToolRegistry::class, $toolHook);
            }
        }
        
        /**
         * SCHRITT 7: Tools registrieren (optional)
         * 
         * Falls dein Modul AI/Chat-Tools hat:
         * 
         * $this->registerTools();
         */
    }

    /**
     * Registriert alle Livewire-Komponenten automatisch
     * 
     * Scant das src/Livewire/ Verzeichnis rekursiv und registriert
     * alle PHP-Dateien als Livewire-Komponenten.
     * 
     * NAMING CONVENTION:
     * - Datei: src/Livewire/Dashboard.php
     * - Namespace: Platform\FoodAlchemist\Livewire\Dashboard
     * - Alias: foodalchemist.dashboard
     * 
     * - Datei: src/Livewire/Entity/Index.php
     * - Namespace: Platform\FoodAlchemist\Livewire\Entity\Index
     * - Alias: foodalchemist.entity.index
     * 
     * @return void
     */
    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\FoodAlchemist\\Livewire';
        $prefix = 'foodalchemist';

        // Kritische eingebettete Komponenten explizit registrieren: diese Tags werden in
        // Blade direkt gerendert und dürfen nicht von Auto-Discovery/Cache-Zustand abhängen.
        Livewire::component('foodalchemist.orders.editor', \Platform\FoodAlchemist\Livewire\Orders\Editor::class);

        // Spec 84 E-4: Alias → Klasse aus dem Scan-Cache (mit Rückfall auf den Live-Scan, kein Cache-Befehl nötig).
        // Spart je Request das Autoloaden aller Livewire-Klassen (class_exists im Scan).
        $map = \Platform\FoodAlchemist\Support\ScanCache::livewire(fn () => $this->livewireLiveScan($basePath, $baseNamespace, $prefix));
        foreach ($map as $alias => $class) {
            Livewire::component($alias, $class);
        }
    }

    /** @return array<string, class-string> Alias → Klasse (bisheriger Scan) */
    private function livewireLiveScan(string $basePath, string $baseNamespace, string $prefix): array
    {
        $map = [];
        if (! is_dir($basePath)) {
            return $map;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (! class_exists($class)) {
                continue;
            }

            // Alias generieren (z.B. Settings/Einheiten -> settings.einheiten).
            // WICHTIG: jedes Pfad-Segment einzeln kebab-en — Str::kebab über den ganzen
            // Pfad macht aus "Settings/Einheiten" sonst "settings/-einheiten" (M1-01-Fund).
            $aliasPath = collect(explode(DIRECTORY_SEPARATOR, str_replace('.php', '', $relativePath)))
                ->map(fn (string $segment) => Str::kebab($segment))
                ->implode('.');
            $map[$prefix . '.' . $aliasPath] = $class;
        }
        ksort($map);

        return $map;
    }
}
