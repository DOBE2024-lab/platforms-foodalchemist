<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;

/**
 * Spec 03 · L2 · KI-Kundentext für Foodbook-Einleitung und Kapitel-Hinführung (98 · 3.3 S4-3, aus FoodbookService verschoben).
 * Liefert nur Vorschläge (persistiert nichts außer der Gateway-Audit-Zeile). Nutzt Basis und Vokabular, nie die Fassade.
 */
final class FoodbookKundentext
{
    public function __construct(
        private FoodbookBasis $basis,
        private FoodbookVokabular $vokabular,
    ) {}

    // ── Spec 03 · L2: KI-Kundentext ─────────────────────────────────────────────
    //
    // Löst den M11-08-Platzhalter ab (kiAndockKontext, gelöscht Spec 85): der sammelte nur die Eingaben,
    // hier läuft der Call. Bewusst NUR Vorschlag — geschrieben wird nichts, auch nicht
    // `description`. Übernehmen ist ein eigener, menschlicher Akt in der Fläche
    // (Backup-Lehre 2026-06-30: ein Generator, der ein handgeschriebenes Kundentext-Feld
    // still überschreibt, vernichtet Arbeit, die nirgends versioniert ist).

    /**
     * Kundentext-Vorschlag für die Foodbook-Einleitung. Persistiert NICHTS außer der
     * Audit-Zeile des Gateways (GL-07 I3). Ohne gebundenen Provider wirft `propose()`
     * typisiert (KiNichtVerfuegbar/KiDeaktiviert) — hier bewusst NICHT geschluckt,
     * die Fläche formuliert die Meldung.
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiKundentextVorschlag(Team $team, int $foodbookId): array
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->basis->guard($fb, $team);                                    // D1: Schreiben/Erzeugen nur durchs Besitzer-Team

        // Workstream W (MCP-Steuerbarkeit D7): Kundentext an Cross-Cutting-Fakten erden (Anti-Marker
        // etc.) — Guardrail fürs kundensichtbare Wording. Wirkt Web + MCP (Parität).
        $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)
            ->contextFor($team, 'foodbook.kundentext', (string) ($fb->label ?: 'Foodbook'));

        $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
            'foodbook.kundentext',
            $this->kundentextKontext($team, $fb),
            [
                'food_dna_foodbook_id' => (int) $fb->id,
                // Ebene 2 der DNA-Kette: der Endkunde des Foodbooks (Muster wie kiKundentext am Block)
                'food_dna_crm_company_id' => $fb->crm_company_id !== null ? (int) $fb->crm_company_id : null,
                'target_table' => 'foodalchemist_foodbooks',
                'target_id' => (int) $fb->id,
                'knowledge' => $wissen['block'] ?? null,
                'knowledge_used' => $wissen['files_used'] ?? null,
            ],
        );

        $text = trim((string) ($proposal->werte['text'] ?? ''));
        if ($text === '') {
            // Leere Antwort NICHT als Erfolg verkaufen — sonst zeigt die Vorschau ein leeres
            // Kästchen und „Übernehmen" würde das Feld leeren.
            throw new \RuntimeException('Die KI hat keinen Text geliefert. Bitte erneut versuchen.');
        }

        return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
    }

    /**
     * Spec 03 · L2b — dasselbe für die **Kapitel**-Ebene (`foodbook_chapters.description`).
     * Eigener Einstieg, aber derselbe Prompt-Key: die Ebene steht im Kontext (`ebene`),
     * nicht im Key. Persistiert ebenfalls nichts — Übernehmen bleibt ein menschlicher Akt.
     *
     * @return array{text: string, confidence: ?float, call_log_id: ?int}
     */
    public function kiKapitelKundentextVorschlag(Team $team, int $kapitelId): array
    {
        $k = $this->basis->ownedKapitel($team, $kapitelId);           // D1: Pflege nur durchs Besitzer-Team
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($k->foodbook_id);

        // Workstream W (D7): dieselbe Cross-Cutting-Erdung wie am Buch-Kundentext (geteilter Prompt-Key).
        $wissen = app(\Platform\FoodAlchemist\Services\Ai\KnowledgeContextService::class)
            ->contextFor($team, 'foodbook.kundentext', (string) ($k->title ?: $fb->label ?: 'Kapitel'));

        $proposal = app(\Platform\FoodAlchemist\Services\Ai\AiGatewayService::class)->propose(
            'foodbook.kundentext',
            $this->kapitelKundentextKontext($team, $fb, $k),
            [
                'food_dna_foodbook_id' => (int) $fb->id,
                'food_dna_crm_company_id' => $fb->crm_company_id !== null ? (int) $fb->crm_company_id : null,
                // Ziel ist das KAPITEL, nicht das Buch — die Audit-Zeile muss zeigen, welches
                // Feld der Vorschlag füllen soll (sonst sind n Kapitel-Calls nicht unterscheidbar).
                'target_table' => 'foodalchemist_foodbook_chapters',
                'target_id' => (int) $k->id,
                'knowledge' => $wissen['block'] ?? null,
                'knowledge_used' => $wissen['files_used'] ?? null,
            ],
        );

        $text = trim((string) ($proposal->werte['text'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Die KI hat keinen Text geliefert. Bitte erneut versuchen.');
        }

        return ['text' => $text, 'confidence' => $proposal->confidence, 'call_log_id' => $proposal->callLogId];
    }

    /**
     * Kontext-Vertrag des Kundentexts: WAS im Angebot steht (Gliederung über die
     * Wording-Kette — dieselben Kunden-Labels wie im PDF, nicht die internen Namen)
     * + WIE es gerahmt ist (Leitplanken) + das Roh-Briefing als Umformungs-Vorlage.
     * Die Marken-/Schreibstil-Kette hängt `AiGatewayService::propose` selbst an.
     *
     * @return array<string, mixed>
     */
    private function kundentextKontext(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $voll = $this->basis->detail($team, (int) $fb->id) ?? $fb;

        $gliederung = [];
        foreach ($voll->chapters as $k) {
            $gliederung[] = $this->kundentextKapitelZeile($k);
            if (count($gliederung) >= 20) {
                break;
            }
        }

        $briefing = trim((string) $voll->description);

        return [
            'ebene' => 'foodbook',
            'titel' => $voll->label,
            'kunde' => $voll->crmCompany?->display_name,
            'personen' => $voll->personen,
            'briefing_ist' => $briefing !== '' ? $briefing : null,
            'gliederung' => $gliederung,
            'leitplanken' => $this->vokabular->leitplanken($team, $voll),
        ];
    }

    /**
     * Kontext-Vertrag der Kapitel-Ebene. Drei Unterschiede zur Buch-Ebene, jeder mit Grund:
     *  · Die `gliederung` ist auf DIESES Kapitel geschnitten (plus seine Unterkapitel — ein
     *    Eltern-Kapitel ist eine Klammer, sein Inhalt hängt darunter). Die Nachbar-Kapitel
     *    gehören nicht dazu: die Hinführung soll das Kapitel eröffnen, nicht das Buch.
     *  · `briefing_ist` ist der **Kapitel**-Text, falls schon einer steht (Umformen statt
     *    Neuschreiben, wie auf der Buch-Ebene). Das Buch-Briefing kommt getrennt als
     *    `rahmen_einleitung` mit — damit die Hinführung nicht die Einleitung wiederholt.
     *  · `leitplanken` werden MIT Kapitel aufgelöst (Zielgruppen-/Kreativ-Kaskade
     *    Kapitel → Foodbook), sonst schriebe die KI gegen den Buch-Default.
     *
     * @return array<string, mixed>
     */
    private function kapitelKundentextKontext(Team $team, FoodAlchemistFoodbook $fb, FoodAlchemistFoodbookKapitel $k): array
    {
        $gliederung = [$this->kundentextKapitelZeile($k)];
        foreach ($k->children()->limit(19)->get() as $kind) {
            $gliederung[] = $this->kundentextKapitelZeile($kind);
        }

        $kapitelText = trim((string) $k->description);
        $rahmen = trim((string) $fb->description);

        // Fix (a) / #2: der KAPITEL-Schreibstil-Override dominiert (falls gesetzt) — sonst hängt die
        // Cascade in propose() den Foodbook-/Team-Default an. So folgt die Kapitel-Hinführung dem
        // Kapitel-Stil, konsistent zum Kapitel-Wording. sprach_duktus, nicht nur der Name.
        $stil = $k->writingStyle;
        $stilKontext = $stil !== null ? array_filter([
            'schreibstil' => $stil->name,
            'schreibstil_anweisung' => trim((string) $stil->sprach_duktus) ?: null,
            'schreibstil_beispiele' => trim((string) $stil->beispiele_md) ?: null,
        ], fn ($v) => $v !== null) : [];

        return [
            'ebene' => 'kapitel',
            'titel' => trim((string) ($k->consumer_title ?: $k->title)),
            'foodbook_titel' => $fb->label,
            'kunde' => $fb->crmCompany?->display_name,
            'personen' => $fb->personen,
            'briefing_ist' => $kapitelText !== '' ? $kapitelText : null,
            'rahmen_einleitung' => $rahmen !== '' ? $rahmen : null,
            'gliederung' => $gliederung,
            'leitplanken' => $this->vokabular->leitplanken($team, $fb, null, $k),
        ] + $stilKontext;
    }

    /**
     * Eine Kapitel-Zeile der KI-Gliederung: Kunden-Label des Kapitels + seine sichtbaren
     * Positionen über die Wording-Kette. Von beiden Ebenen geteilt, damit ein Kapitel in
     * der Buch- und in der Kapitel-Sicht dasselbe zeigt.
     *
     * @return array{kapitel: string, positionen: list<string>}
     */
    private function kundentextKapitelZeile(FoodAlchemistFoodbookKapitel $k): array
    {
        $positionen = [];
        foreach ($k->blocks as $b) {
            if (! $b->visible) {
                continue;                                            // Export-Filter gilt auch für die KI-Sicht
            }
            $t = trim((string) $this->basis->dokBlockLabel($b));
            if ($t !== '') {
                $positionen[] = $t;
            }
        }

        return [
            'kapitel' => trim((string) ($k->consumer_title ?: $k->title)),
            // Deckel gegen Prompt-Aufblähung: ein 60-Positionen-Buffet-Kapitel braucht
            // die KI nicht vollständig, um den Bogen zu spannen.
            'positionen' => array_slice(array_values(array_unique($positionen)), 0, 12),
        ];
    }
}
