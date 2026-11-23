<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\BriefingLeitplankenService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningSessionService;

/**
 * Spec 85 C1 PR-4 (C-c) · der Start eines Laufs aus dem Briefing, aus `Livewire\Planung\Brief::goKaskade` herausgelöst
 * (wortgleich verschoben, Muster {@see ReglerParams}). Zustandsfrei: bekommt den Formularstand, liefert den neuen Stand zurück.
 *
 * Prüfungen (Ziel-VK, Menü-Leitplanken), das Spiegeln auf die Session und die Meldungen bleiben in der Komponente. Hier:
 * Leitplanken als generation_params persistieren, Suchbegriffe nachziehen und speichern, Lauf-Params bauen (Titel-Anker,
 * Seed-Anker, Suchbegriffe nur Depth-1), geplanten Concept-Pfad auflösen, Lauf starten, KI-Kopf-Plan verbrauchen.
 */
final class KaskadenStart
{
    public function __construct(
        private PlanningCascadeService $cascade,
        private PlanningSessionService $sessions,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $eingabe  Tab-Eingaben je Scope
     * @param  array<string, array<string, mixed>>  $regler  Leitplanken je Scope
     * @param  array{scope?: string, slugs?: list<string>}  $seedPin  Composer-Anker (composerSeedPin)
     * @return array{run: ?FoodAlchemistCascadeRun, fehler: ?string, eingabe: array, seed_pin: array, plan_concept_id: ?int}
     *         `run` null ⇒ `fehler` trägt die Meldung; `eingabe`/`seed_pin`/`plan_concept_id` sind auch dann der neue Stand.
     */
    public function starte(Team $team, FoodAlchemistPlanningSession $session, string $scope, array $eingabe, array $regler,
        array $seedPin, ?int $planConceptId): array
    {
        // NUR der Start-Tab zählt: seine Leitplanken werden persistiert und propagieren die ganze
        // Kaskade nach unten (Start-Tab gilt für alles darunter — User-Entscheid 2026-08-14).
        $params = (new ReglerParams($regler, $eingabe))->reglerParams($scope);
        // FAIL-SOFT: die Regler fließen ohnehin über die Lauf-`params` in den Depth-1-Job — die
        // Session-Persistenz ist NUR für die Fan-out-Vererbung. Kippt sie, darf der Go NICHT sterben.
        try {
            $this->sessions->setGenerationParams($team, $session->id, $params);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] setGenerationParams übersprungen (Fan-out-Vererbung aus) — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        // Spec 80 A4: ohne Suchbegriffe (Leitplanken nie abgeleitet) einmal nachziehen — derselbe Aufruf,
        // nur die Begriffe werden übernommen, die Regler bleiben unangetastet. Fail-soft: ohne Begriffe
        // sucht der Generator wie bisher mit dem Briefing.
        if (in_array($scope, ['rezept', 'gericht'], true) && empty($eingabe[$scope]['suchbegriffe'])
            && trim((string) ($eingabe[$scope]['brief'] ?? '')) !== '') {
            try {
                $abgeleitet = app(BriefingLeitplankenService::class)
                    ->ausBriefing($team, (string) $eingabe[$scope]['brief'], null, $scope);
                $eingabe[$scope]['suchbegriffe'] = (array) ($abgeleitet['suchbegriffe'] ?? []);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Planung] Suchbegriffe beim Go nicht ableitbar', ['error' => $e->getMessage()]);
            }
        }
        $suchbegriffe = FoodAlchemistPlanningSession::suchbegriffeFuerLauf((array) ($eingabe[$scope]['suchbegriffe'] ?? []));
        try {
            $session = $this->sessions->setzeSuchbegriffe($team, (int) $session->id, $scope, (array) ($eingabe[$scope]['suchbegriffe'] ?? []));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Planung] Suchbegriffe nicht gespeichert — evtl. Migration fehlt', ['error' => $e->getMessage()]);
        }
        // L5: Titel als Namens-Anker — getippten Titel getrennt vom Brief in die Lauf-Params geben
        // (NICHT in die persistierten Session-Params, sonst erbte JEDES Fan-out-Kind den Gericht-Titel
        // als Namen). Nur der Depth-1-Job sieht `titel_vorgabe`; der Generator nimmt ihn als Namen.
        $laufParams = $params;
        $titelVorgabe = trim((string) ($eingabe[$scope]['titel'] ?? ''));
        if ($titelVorgabe !== '' && $scope !== 'concept') {   // concept: Name kommt aus dem KI-Kopf (name_claim)
            $laufParams['titel_vorgabe'] = $titelVorgabe;
        }
        // Foodpairing-Composer: gezielte Seed-Anker NUR in die Lauf-Params (Depth-1-lokal, exakt wie
        // titel_vorgabe) — NIE in setGenerationParams, sonst erbte jedes Fan-out-Kind die Anker.
        // Quelle: der persistente Seed-Pin (Composer-Übernahme → Erstellen-Tab, überlebt den
        // Tab-Wechsel), nur für den passenden Scope. Pin nach Verwendung leeren → kein Re-Seed bei
        // späterem unabhängigem Go.
        $seed = [];
        $seedAusPin = false;
        if (($seedPin['scope'] ?? null) === $scope) {
            $seed = array_values($seedPin['slugs'] ?? []);
            $seedAusPin = true;
        }
        if ($seed !== [] && $scope !== 'concept') {
            $laufParams['seed_anker'] = array_values($seed);
        }
        // Spec 80: Suchbegriffe NUR Depth-1 (wie titel_vorgabe) — Kind-Rezepte bekommen später die
        // Begriffe ihrer Komponente (Teil B), nicht die der Wurzel.
        if ($suchbegriffe !== [] && $scope !== 'concept') {
            $laufParams['suchbegriffe'] = $suchbegriffe;
        }
        if ($seedAusPin) {
            $seedPin = [];
        }
        $optionen = [
            'created_via' => 'plan_go',
            'brief' => (new ReglerParams($regler, $eingabe))->effektiverBrief($scope),
            'params' => $laufParams,
            'voll_anreichern' => (bool) ($regler[$scope]['voll_anreichern'] ?? true),   // Default AN (s. $reglerDefaults)
            // Gericht: erst textlicher Bauplan (inkl. Komponenten), Rezept-Draft erst nach Blitz/Freigabe.
            'proposal_first' => $scope === 'gericht',
            // Spec 80 B1: Basisrezept erst als Komponenten-Plan (Mensch bestätigt), dann Bau.
            'plan_first' => $scope === 'rezept',
        ];
        // GEPLANTER PFAD (Etappe 2b, „Beide Pfade behalten"): wurde vorab ein KI-Kopf-Plan ausgearbeitet
        // und im Conceptor geprüft (planConceptId), referenziert der Concept-Go dieses Draft-Concept
        // (existing_concept_id → kein neuer GenerateConceptJob), statt neu zu generieren. Ohne Plan =
        // SCHNELL-PFAD (frische Generierung). FAIL-SOFT: ist der Plan inzwischen weg (gelöscht/Team-fremd),
        // NICHT hart blocken — Plan still verwerfen und frisch generieren (ein leerer/toter Plan kippt den Go nicht).
        if ($scope === 'concept' && (int) ($planConceptId ?? 0) > 0) {
            if (FoodAlchemistConcept::where('team_id', $team->id)->whereKey($planConceptId)->exists()) {
                $optionen['existing_concept_id'] = (int) $planConceptId;
            } else {
                $planConceptId = null;
            }
        }
        try {
            $run = $this->cascade->starteKaskade($team, $scope, $session, (string) ($eingabe[$scope]['creative_mode'] ?? 'voll_kreativ'), $optionen);
        } catch (\Throwable $e) {
            return ['run' => null, 'fehler' => $e->getMessage(), 'eingabe' => $eingabe, 'seed_pin' => $seedPin, 'plan_concept_id' => $planConceptId];
        }
        // Verbrauch AN DEN SCOPE binden: nur der Concept-Go verbraucht den KI-Kopf-Plan. Sonst löschte ein
        // Basisrezept-/Gericht-Go den vorbereiteten Concept-Plan, obwohl er für diesen Lauf nie gelesen wurde.
        if ($scope === 'concept') {
            $planConceptId = null;    // Plan verbraucht (referenziert ODER frisch generiert) → nächster Go Schnell-Pfad
            // #53 persistent: auch den gespeicherten Zeiger lösen (Plan verbraucht) — sonst böte ein
            // Reload nach dem Go den bereits verbrauchten Plan wieder an. Fail-soft.
            if ($session->plan_concept_id !== null) {
                try {
                    $this->sessions->setzePlanConcept($team, (int) $session->id, null);
                } catch (\Throwable) {
                    // Persistenz-Fehler darf den bereits gestarteten Lauf nicht kippen.
                }
            }
        }

        return ['run' => $run, 'fehler' => null, 'eingabe' => $eingabe, 'seed_pin' => $seedPin, 'plan_concept_id' => $planConceptId];
    }
}
