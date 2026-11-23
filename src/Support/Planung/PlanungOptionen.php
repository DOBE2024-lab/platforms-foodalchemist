<?php

namespace Platform\FoodAlchemist\Support\Planung;

/**
 * 98 · 1.3b · Auswahllisten und Regler-Grenzen der Planung, wortgleich aus `Livewire\Planung\Index` verschoben,
 * damit Services (Sprachbefehl-Agent) und Support (ReglerParams) sie lesen, ohne eine Livewire-Klasse zu kennen.
 * Index spiegelt die Konstanten für Blade und Komponente.
 */
final class PlanungOptionen
{
    /** Kreativ-Modus: Anzeige-Label je Wert (Parent-Detail, Brief-Reiter). Spec 85 C1 PR-4: aus planung/index.blade.php hierher. */
    public const MODUS_LABEL = ['voll_kreativ' => 'Frei kreativ', 'hybrid' => 'Kreativ mit Bestand', 'datenbank' => 'Nur Bestand'];

    /** Kreativ-Modus: Wirkungs-Hinweis je Wert (die EINE Achse für Wiederverwendung). */
    public const MODUS_HINWEIS = [
        'voll_kreativ' => 'Freie Gerichtsidee. Vorhandene Basisrezepte werden trotzdem wiederverwendet, neu entsteht nur eine echte Lücke.',
        'hybrid' => 'Freie Idee mit Bezug zum Bestand. Vorhandene Gerichte und Basisrezepte werden bevorzugt wiederverwendet.',
        'datenbank' => 'Ausschließlich vorhandene Gerichte und Basisrezepte. Lücken bleiben sichtbar und werden nicht neu erstellt.',
    ];

    /**
     * Spec 55 Nachtrag (Agent-am-Brief): vorher NUR als rohe `<option>`-Liste in
     * leitplanken.blade.php gepflegt — als Konstante extrahiert, damit der Sprachbefehl-Agent
     * (VoiceCommandService) beim Bau einer Rückfrage-Chip-Auswahl GENAU dieselben Werte
     * anbietet, nicht eine zweite, unabhängig gepflegte Kopie. Wert=>Label, `''` = kein
     * Sektor-Constraint (egal/universell).
     */
    public const SEKTOR_OPTIONEN = ['' => '(egal/universell)'] + \Platform\FoodAlchemist\Support\Sektor::LABELS;

    // CQ 2.5b: Anlass (OCCASION_OPTIONEN) und Serviceform (SERVICEFORM_OPTIONEN) kommen aus den DB-Vokabularen ({@see PlanungVokabular}).

    /**
     * Spec 55 Nachtrag (Agent-am-Brief): Pflicht-Leitplanken je Scope, gegen die der
     * Sprachbefehl-Agent den ECHTEN Regler-Stand prüft (nicht nur den gesprochenen Satz) —
     * fehlt eines, fragt der Agent GEZIELT danach (VoiceCommandService::luecken()). Dominique
     * 2026-09-19: Basisrezept = Menge/Einheit (Halbfabrikat, kein Teller); Gericht = Pax/
     * Portion/Anlass/Serviceform; Concept teilt sich Pax/Anlass/Serviceform mit Gericht (hat
     * aber KEINE Portion — Concept ist das ganze Menü, s. `ziel_portion_g`-Ausschluss oben).
     * „Format" (Sektor/Anlass/Personen) hat noch KEINEN eigenen Regler-Satz (`fmtBrief` ist
     * ein flaches Diktat-Ziel, kein Creation-Scope) — bewusst NICHT hier, siehe Spec 55 „Offene
     * Punkte" (separates Vorhaben, kein Bestandteil dieses Nachtrags).
     */
    public const MANDATORY_LEITPLANKEN = [
        'rezept' => ['ziel_menge', 'ziel_einheit'],
        'gericht' => ['pax', 'ziel_portion_g', 'occasion', 'serviceform'],
        'concept' => ['pax', 'occasion', 'serviceform'],
    ];

    /**
     * Spec 55 Nachtrag: die vom Sprachbefehl-Agenten SCHREIBBAREN Regler-Felder — Union aus
     * MANDATORY_LEITPLANKEN plus den ursprünglichen Spec-55-Feldern (sektor/level waren schon
     * vorher Teil der freien Lückencheck-Frage, auch ohne in MANDATORY_LEITPLANKEN zu stehen).
     * EIGENE Konstante statt eines Array-Merges an der Nutzungsstelle: die Whitelist ist eine
     * Sicherheitsgrenze (`voiceKomponentenUebernehmen()`), sie soll an EINER Stelle stehen,
     * nicht implizit aus einer Formel entstehen, die sich mit MANDATORY_LEITPLANKEN mitändert.
     */
    public const AGENT_SCHREIBBARE_REGLER = [
        'sektor', 'occasion', 'pax', 'ziel_vk', 'level', 'serviceform', 'ziel_menge', 'ziel_einheit', 'ziel_portion_g',
    ];

    /**
     * Ziel-Mengen-Einheiten fürs BASISREZEPT (Halbfabrikat) — value=>Label. Ein Basisrezept ist keine
     * Teller-Portion für N Gäste, sondern eine Charge in einer Einheit (2 L Sauce, 5 kg Teig, 30 Stk).
     * Kuratierte KI-Vorgabe-Liste (kein Vocab-Zwang — reiner Prompt-Hint), reglerParams whitelistet dagegen.
     */
    public const MENGE_EINHEITEN = [
        '' => '(egal)',
        'l' => 'Liter', 'ml' => 'Milliliter', 'kg' => 'Kilogramm', 'g' => 'Gramm',
        'stk' => 'Stück', 'portionen' => 'Portionen',
    ];
}
