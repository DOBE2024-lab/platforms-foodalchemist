<?php

namespace Platform\FoodAlchemist\Support;

/**
 * 98 · 1.3b · Lesbare Namen für Prompt- und Routing-Schlüssel, wortgleich aus `Livewire\Settings\Wissenssteuerung`
 * verschoben, damit Tools (KiKlassenGetTool) sie ohne Livewire-Klasse nutzen. Die Seite zeigte bisher nur technische
 * Schlüssel (recipe.generator, discovery, …); diese Tabellen übersetzen sie in Küchensprache. Reine Anzeige:
 * gespeichert und verglichen wird weiter mit den Schlüsseln.
 */
final class KiSchrittLabels
{
    /** Bereich = Teil vor dem ersten Punkt eines Prompt-Keys. */
    public const BEREICH_LABEL = [
        'chat' => 'Chat', 'component' => 'Komponente', 'concept' => 'Konzept', 'conformance' => 'Regelwerk-Prüfung',
        'demo' => 'Test', 'foodbook' => 'Foodbook', 'format' => 'Format', 'gp' => 'Grundprodukt',
        'planning' => 'Planung', 'planung' => 'Planung', 'praesentation' => 'Präsentation', 'price' => 'Preis',
        'recipe' => 'Basisrezept', 'signal' => 'Signal', 'trend' => 'Trendradar', 'vk' => 'Gericht', 'voice' => 'Sprachbefehl',
    ];

    /** Arbeitsschritt = Teil nach dem ersten Punkt. Unbekanntes wird lesbar gemacht statt versteckt. */
    public const SCHRITT_WORT = [
        'allergene' => 'Allergene', 'anker' => 'Aroma-Anker', 'bauart' => 'Bauart', 'brief_geruest' => 'Grundgerüst aus dem Auftrag',
        'category' => 'Kategorie', 'check' => 'Prüfung', 'cluster_label' => 'Trends einordnen', 'command' => 'Befehl',
        'condition' => 'Zustand', 'conformance_revise' => 'Regelwerk-Korrektur', 'description' => 'Beschreibung',
        'design_css' => 'Eigenes Design', 'dichteklasse' => 'Dichteklasse', 'dish_proposal_revise' => 'Gerichtvorschlag überarbeiten',
        'domain' => 'Warenkunde', 'echo' => 'Echo', 'eigenschaften' => 'Eigenschaften', 'equipment' => 'Geräte',
        'extract' => 'Rezept einlesen', 'garverlust' => 'Garverlust', 'generator' => 'Erzeugen', 'geschmack' => 'Geschmack',
        'grundgeruest' => 'Grundgerüst', 'kapitel_ideen' => 'Kapitel-Ideen', 'kohaerenz' => 'Stimmigkeit', 'kundentext' => 'Kundentext',
        'la_suggest' => 'Artikelvorschlag', 'leitplanken' => 'Leitplanken aus dem Auftrag', 'level' => 'Niveau',
        'margin_levers' => 'Margen-Hebel', 'marketing' => 'Verkaufstext', 'message' => 'Nachricht', 'naehrwerte' => 'Nährwerte',
        'name_putzen' => 'Namen bereinigen', 'pairing' => 'Pairing', 'piece_default_g' => 'Stückgewicht', 'plan' => 'Plan',
        'plating' => 'Anrichten', 'plausi' => 'Plausibilität', 'posten' => 'Posten', 'production_depth' => 'Fertigungstiefe',
        'recipe_category_suggest' => 'Rezept-Kategorie vorschlagen', 'recipe_naming_suggest' => 'Rezeptnamen vorschlagen',
        'regeneration' => 'Regenerieren', 'replacement_suggest' => 'Ersatz vorschlagen', 'review' => 'Prüfen', 'role' => 'Rolle',
        'rollen' => 'Rollen', 'sektor' => 'Sektor', 'sensorik' => 'Sensorik', 'servier_vehikel' => 'Servier-Vehikel',
        'serving_form_suggest' => 'Servierform vorschlagen', 'speisen_klasse' => 'Speisenklasse', 'steps' => 'Arbeitsschritte',
        'suggest' => 'Vorschlag', 'supplier_inquiry' => 'Lieferanten-Anfrage', 'tags' => 'Schlagworte', 'teller_heber' => 'Teller-Heber',
        'term_la_rank' => 'Artikel-Rangfolge', 'titel_vorschlag' => 'Titelvorschlag', 'ueberarbeiten' => 'Überarbeiten',
        'verpackungsmasse' => 'Verpackungsmaße', 'vk_release_advice' => 'Empfehlung Neu-Veröffentlichen', 'wording' => 'Formulierung',
        'zaehl_einheiten' => 'Zähleinheiten',
    ];

    /** Schlüssel ohne Punkt-Schema (Alt-Schlüssel der Routing-Ebene). */
    public const SCHRITT_SONDER = [
        'ai_generate_recipe' => 'Rezept- und Gericht-Erzeugung',
        'ai_suggest_pairings' => 'Pairing-Vorschläge',
        'ai_infer_ankers' => 'Aroma-Anker ableiten',
    ];

    /** Verwendung einer Kategorie bzw. Art (Routing-Modus). */
    public const MODUS_LABEL = [
        'always' => 'Immer vollständig', 'discovery' => 'Passendes suchen', 'grounding' => 'Je Hauptzutat',
        'none' => 'Bewusst nicht', 'resolve' => 'Werte auflösen',
    ];

    /** Lesbarer Name eines Prompt- oder Routing-Schlüssels (z. B. recipe.generator → Basisrezept · Erzeugen). */
    public static function schrittLabel(?string $key): string
    {
        $key = (string) $key;
        if ($key === '') {
            return '–';
        }
        if (isset(self::SCHRITT_SONDER[$key])) {
            return self::SCHRITT_SONDER[$key];
        }
        [$bereich, $rest] = str_contains($key, '.') ? explode('.', $key, 2) : [$key, ''];
        $bereichText = self::BEREICH_LABEL[$bereich] ?? self::lesbar($bereich);

        return $rest === '' ? $bereichText : $bereichText.' · '.(self::SCHRITT_WORT[$rest] ?? self::lesbar($rest));
    }

    /** snake_case/Punkt-Werte als lesbarer Text, z. B. schule_kita → Schule kita. */
    public static function lesbar(?string $wert): string
    {
        return ucfirst(trim(str_replace(['_', '.'], ' ', (string) $wert)));
    }

    public static function modusLabel(?string $modus): string
    {
        return self::MODUS_LABEL[(string) $modus] ?? self::lesbar($modus);
    }
}
