<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\GpAromaService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * Aroma-Reiter: „Anker vorschlagen" + „Mit KI schätzen" (Geschmacksprofil) — EIN Weg für GP-Dialog und Detail-Ansicht
 * (Dominique 27.10.2026: die Knöpfe fehlten in der Detail-Ansicht). Die Komponente liefert nur Team, GP und wohin Meldungen gehen.
 */
trait HatGpAromaKi
{
    /** @var ?array{werte: array<string,float>, geschaetzt: list<string>, gemessen: array<string,float>, texturen: list<array>, confidence: float, reasoning: ?string, call_log_id: ?int} Vorschau — NICHTS persistiert (GL-07) */
    public ?array $sensorikVorschlag = null;

    /** Das GP, auf dem der Aroma-Reiter gerade steht (sichtbar fürs Team), oder null. */
    abstract protected function aromaGp(): ?FoodAlchemistGp;

    /** Fehler bzw. Hinweis aus dem Aroma-Reiter anzeigen (jede Komponente hat ihre eigenen Felder dafür). */
    abstract protected function aromaMeldung(?string $fehler, ?string $hinweis = null): void;

    /**
     * KI-Knopf „Anker vorschlagen" (Dominique 2026-10-12, Geschwister-Weg 27.10.): sind sich die Geschwister-GPs derselben
     * Hauptzutat einig, werden ihre Anker übernommen (kostenlos, Quelle `geschwister`). Sonst Kern-Anker per KI aus dem Vokabular,
     * sofort gesetzt mit ehrlicher Provenienz `ai_inferred` + Konfidenz — Handarbeit (manual) bleibt geschützt.
     *
     * Volle Kern-Grenze (17.11.2026, „Curry: Brokkoli" mit 3 Kern-Ankern): der Knopf sucht dann einen NEBENANKER (bis zur Neben-Grenze)
     * und sagt das. Jeder Ausgang meldet sich — nie still nichts (auch nicht, wenn die KI einen schon verknüpften Anker nennt).
     */
    public function ankerKiVorschlagen(BulkEnrichService $bulk, PairingService $pairing): void
    {
        $this->aromaMeldung(null);
        $team = Auth::user()?->currentTeamRelation;
        $gp = $this->aromaGp();
        if ($team === null || $gp === null) {
            return;
        }
        $aroma = app(GpAromaService::class);
        if (! $aroma->hatAnker($gp->id) && ($g = $aroma->geschwisterAnker($gp))['status'] === 'einig') {
            try {
                $n = $aroma->ankerVonGeschwistern($gp);
            } catch (\RuntimeException $e) {
                $this->aromaMeldung($e->getMessage());

                return;
            }
            if ($n > 0) {
                $this->aromaMeldung(null, "Anker von {$g['geschwister']} Geschwister-GP(s) derselben Hauptzutat übernommen (ohne KI).");

                return;
            }
        }
        $rolle = 'kern';
        if (\Platform\FoodAlchemist\Support\AnkerGrenze::anzahl($gp->id, 'kern') >= \Platform\FoodAlchemist\Support\AnkerGrenze::KERN) {
            if (\Platform\FoodAlchemist\Support\AnkerGrenze::anzahl($gp->id, 'neben') >= \Platform\FoodAlchemist\Support\AnkerGrenze::NEBEN) {
                $this->aromaMeldung('Alle Plätze belegt: '.\Platform\FoodAlchemist\Support\AnkerGrenze::KERN.' Kern- und '.\Platform\FoodAlchemist\Support\AnkerGrenze::NEBEN
                    .' Nebenanker. Erst einen Anker lösen, dann vorschlagen lassen.');

                return;
            }
            $rolle = 'neben';
        }
        try {
            $v = $bulk->gpAnkerVorschlag($team, $gp, $rolle);
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException) {
            $this->aromaMeldung('KI derzeit nicht verfügbar.');

            return;
        } catch (\Throwable $e) {
            $this->aromaMeldung($e->getMessage());

            return;
        }
        $slug = $v['value']['anchor_slug'] ?? null;
        if ($slug === null) {
            $this->aromaMeldung('KI fand keinen passenden Anker im Vokabular.');

            return;
        }
        if ($slug === 'neutral' && $rolle === 'neben') {
            $this->aromaMeldung(null, 'Kern-Anker sind voll ('.\Platform\FoodAlchemist\Support\AnkerGrenze::KERN.'); die KI fand keinen weiteren Aroma-Träger als Nebenanker — nichts gesetzt.');

            return;
        }
        if ($slug === 'neutral') {
            // wie der Bulk-Weg: festhalten, damit der Backfill das GP nicht erneut fragt (io-Probe 01.11.2026)
            if (! $aroma->hatAnker($gp->id) && $pairing->darfAnkerPflegen($gp, $team)) {
                $aroma->merkeAnkerNeutral($gp->id, (int) $team->id, 'ai', (float) ($v['confidence'] ?? 0.5), 'GP-Editor: KI-Vorschlag neutral'.(($v['reasoning'] ?? '') !== '' ? ' — '.$v['reasoning'] : ''));
            }
            $this->aromaMeldung(null, 'KI: kein Aroma-Träger (neutral) — kein Anker gesetzt.');

            return;
        }
        $ankerId = DB::table('foodalchemist_vocab_pairing_anchors')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($ankerId === null) {
            $this->aromaMeldung("Anker „{$slug}\" ist nicht im Vokabular.");

            return;
        }
        $vorher = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gp->id)->where('anchor_id', $ankerId)->whereNull('deleted_at')->first();
        if ($vorher !== null && ($rolle === 'neben' || $vorher->source === 'manual')) {
            // die KI nennt einen schon verknüpften Anker: nichts zu tun — sagen statt „gesetzt" zu melden
            $this->aromaMeldung(null, "KI schlägt „{$slug}\" vor — der Anker ist schon verknüpft ({$vorher->role}), nichts geändert.");

            return;
        }
        try {
            $pate = ($v['value']['pate'] ?? false) === true;   // wie der Bulk-Weg: Ersatz-Anker gekennzeichnet, mit Begründung
            $gesetzt = $pairing->setGpAnkerInference($team, $gp->id, (int) $ankerId, (float) ($v['confidence'] ?? 0.5),
                ($pate ? '[Pate] '.$v['value']['pate_begruendung'].' — ' : '').'GP-Editor: KI-Vorschlag aus dem Anker-Vokabular'
                    .($rolle === 'neben' ? ' (Nebenanker, Kern-Anker voll)' : '').(($v['reasoning'] ?? '') !== '' ? ' — '.$v['reasoning'] : ''),
                $pate ? \Platform\FoodAlchemist\Support\AnkerQuelle::PATE : \Platform\FoodAlchemist\Support\AnkerQuelle::KI, $rolle);
            if ($gesetzt) {
                $aroma->vergissAnkerNeutral($gp->id);
            }
        } catch (\RuntimeException $e) {
            $this->aromaMeldung($e->getMessage());

            return;
        }
        if (! $gesetzt) {
            $this->aromaMeldung(null, "KI schlägt „{$slug}\" vor — der Anker ist von Hand verknüpft, nichts geändert.");

            return;
        }
        $art = $rolle === 'neben' ? 'Nebenanker' : 'Anker';
        $this->aromaMeldung(null, ($pate ? "Pate „{$slug}\" als {$art} per KI gesetzt (Ersatz, die Zutat hat keinen eigenen Anker; " : "{$art} „{$slug}\" per KI gesetzt (")
            .($rolle === 'neben' ? 'Kern-Anker sind voll; ' : '')
            .'Konfidenz '.round((float) ($v['confidence'] ?? 0) * 100).' %).');
    }

    /** „Mit KI schätzen": Vorschau der Wahrnehmung auf allen 7 Achsen (+ Texturen, wenn keine da sind). Schreibt nichts. */
    public function sensorikKiSchaetzen(GpAromaService $aroma): void
    {
        $this->aromaMeldung(null);
        $this->sensorikVorschlag = null;
        $gp = $this->aromaGpKuratierbar();
        if ($gp === null) {
            return;
        }
        if (DB::table('foodalchemist_gp_taste_vectors')->where('gp_id', $gp->id)->value('source') === 'manual') {
            $this->aromaMeldung('Das Profil ist von Hand gepflegt und wird nicht per KI ersetzt.');

            return;
        }
        try {
            $v = $aroma->schaetze($gp);
        } catch (\Platform\FoodAlchemist\Exceptions\KiNichtVerfuegbarException) {
            $this->aromaMeldung('KI derzeit nicht verfügbar.');

            return;
        } catch (\Throwable $e) {
            $this->aromaMeldung($e->getMessage());

            return;
        }
        if ($v === null) {
            $this->aromaMeldung('KI lieferte kein verwertbares Geschmacksprofil.');

            return;
        }
        $this->sensorikVorschlag = $v;
    }

    /** Übernehmen = der EINE Schreib-Moment (GL-07). Ersetzt ein vorhandenes KI-/Import-Profil, nie ein manuelles. */
    public function sensorikUebernehmen(GpAromaService $aroma): void
    {
        $gp = $this->aromaGpKuratierbar();
        $v = $this->sensorikVorschlag;
        if ($gp === null || $v === null) {
            return;
        }
        if (! $aroma->speichereProfil($gp->id, $v['werte'], 'ai', $v['confidence'], $v['reasoning'], $v['texturen'], true)) {
            $this->aromaMeldung('Das Profil ist von Hand gepflegt und bleibt.');

            return;
        }
        app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->stempleAccepted($v['call_log_id']);
        $this->sensorikVorschlag = null;
        $this->aromaMeldung(null, 'Geschmacksprofil per KI übernommen.');
    }

    public function sensorikVerwerfen(): void
    {
        if ($this->sensorikVorschlag !== null) {
            app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->stempleRejected($this->sensorikVorschlag['call_log_id']);
        }
        $this->sensorikVorschlag = null;
    }

    /** Profil ist globale Katalog-Pflege (Tabelle ohne Team-Schlüssel) — nur fürs Kurations-Team. */
    private function aromaGpKuratierbar(): ?FoodAlchemistGp
    {
        $gp = $this->aromaGp();
        if ($gp !== null && ! Curate::canCurate(Auth::user(), $gp)) {
            $this->aromaMeldung('Geschmacksprofil ist Katalog-Pflege — nur fürs Besitzer-Team (D1).');

            return null;
        }

        return $gp;
    }

    /** Anzeige-Zeilen der Vorschau: Wahrnehmung je Achse, dazu Konzentration/Nährwert als Grundlage. */
    public function sensorikVorschauZeilen(): array
    {
        $v = $this->sensorikVorschlag;
        if ($v === null) {
            return [];
        }
        $zeilen = [];
        foreach (SensorikService::DIMS as $d) {
            $zeilen[] = ['label' => SensorikService::DIM_LABEL[$d], 'wert' => $v['werte'][$d] ?? 0.0, 'art' => 'geschätzt',
                'konzentration' => $v['konzentration'][$d] ?? null, 'naehrwert' => $v['naehrwerte'][$d] ?? null];
        }

        return $zeilen;
    }
}
