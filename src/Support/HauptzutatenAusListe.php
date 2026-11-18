<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Hauptzutaten aus der Zutatenliste eines Lieferantenartikels (`supplier_items.ingredients_supplier`), für den Anker-Vorschlag (`gp.anker`).
 *
 * io 18.11.2026 (GP 13361 „Curry: Brokkoli, frisch / (Planet V) / (Bio)", ai_call_log 18533): der Knopf fragte den Nebenanker nur mit
 * Name/Zustand/Warengruppe, die KI sagte „neutral" — Möhre, Rote Linse, Mandel, Zwiebel standen weder im Prompt noch in der Vorauswahl.
 * Die Liste des Lieferanten trägt sie, in Reihenfolge des Anteils (LMIV Art. 18). Hier nur das, was das Aroma trägt:
 *  - Klammern (Unterzutaten, auch geschachtelt), Prozente, Sternchen, Fußnoten („*aus kontrolliert biologischem Anbau") und Spuren-Hinweise weg;
 *  - Wasser, Salz, Öl/Fett, Zucker/Sirup, „Gewürze", „Kräuter", Stärke, Aroma, Lab/Kulturen, Zusatzstoff-Klassen („Säuerungsmittel: Citronensäure") und E-Nummern weg —
 *    sie sagen nichts über die Aroma-Identität eines Produkts, aber stehen in fast jeder Liste;
 *  - die ersten {@see MAX} in Reihenfolge, ohne Doppelte.
 */
final class HauptzutatenAusListe
{
    public const MAX = 8;

    /** Ganze Einträge (klein), die kein Aroma tragen. */
    private const WEG = [
        'wasser', 'trinkwasser', 'quellwasser', 'salz', 'meersalz', 'speisesalz', 'jodsalz', 'siedesalz', 'steinsalz', 'kochsalz',
        'nitritpökelsalz', 'jodiertes speisesalz', 'gewürze', 'gewürz', 'kräuter', 'gewürzmischung', 'gewürzextrakt', 'gewürzextrakte',
        'öl', 'pflanzenöl', 'pflanzliches öl', 'pflanzenfett', 'pflanzliches fett', 'aroma', 'aromen', 'natürliches aroma', 'natürliche aromen',
        'lab', 'mikrobielles lab', 'labaustauschstoff', 'kulturen', 'milchsäurekulturen', 'starterkulturen', 'milchsäurebakterien',
        'würze', 'rauch', 'buchenrauch', 'pökellake', 'hefe', 'zucker', 'dextrose', 'glukosesirup', 'glucosesirup', 'maltodextrin', 'traubenzucker', 'zitronensäure', 'citronensäure',
    ];

    /** Zusatzstoff-Klassen und technische Stoffe: ein Eintrag, der damit beginnt oder darauf endet, fällt weg. */
    private const KLASSEN = [
        'emulgator', 'stabilisator', 'verdickungsmittel', 'säuerungsmittel', 'säureregulator', 'konservierungsstoff', 'antioxidationsmittel',
        'farbstoff', 'geschmacksverstärker', 'backtriebmittel', 'trennmittel', 'feuchthaltemittel', 'festigungsmittel', 'gerinnungsmittel',
        'überzugsmittel', 'füllstoff', 'süßungsmittel', 'mehlbehandlungsmittel', 'komplexbildner', 'schaumverhüter', 'treibgas',
    ];

    /** Endungen technischer Träger: Sonnenblumenöl, Kokosfett, Meersalz, Maisstärke, Raucharoma (nur am Wortende — Salzgurke bleibt). */
    private const ENDUNGEN = ['öl', 'fett', 'salz', 'stärke', 'aroma', 'aromen', 'zucker', 'sirup', 'kultur', 'kulturen', 'labaustauschstoff'];

    /** @return list<string> die Hauptzutaten in Reihenfolge der Liste, höchstens {@see MAX}; leer ohne Liste */
    public static function liste(?string $text, int $max = self::MAX): array
    {
        $s = trim((string) $text);
        if ($s === '') {
            return [];
        }
        $s = (string) preg_replace('/^\s*zutaten\s*:\s*/iu', '', $s);
        // Fußnote „Kräuter* *aus kontrolliert …": ein Sternchen nach Leerraum öffnet die Fußnote; danach steht keine Zutat mehr
        $s = (string) preg_replace('/(?<=\s|^)\*+\s*\p{L}.*$/us', '', $s);
        $s = (string) preg_replace('/(?:das produkt )?(?:kann|enthält)\s+(?:\p{L}+\s+)?spuren.*$/ius', '', $s);
        // geschachtelte Klammern von innen nach außen (Gemüsebrühe (… Gemüse 9 % [Zwiebel, …] …))
        for ($i = 0; $i < 5; $i++) {
            $neu = (string) preg_replace('/\s*[(\[][^()\[\]]*[)\]]/u', '', $s);
            if ($neu === $s) {
                break;
            }
            $s = $neu;
        }
        $s = (string) preg_replace('/[()\[\]]/u', ' ', $s);              // unpaarige Reste
        $s = (string) preg_replace('/\.\s+(?=\p{Lu}).*$/us', '', $s);    // Satzende: danach folgt Fließtext (Hinweise, Hersteller)

        $out = [];
        foreach (preg_split('/[,;]/u', $s) ?: [] as $teil) {
            $z = self::eintrag($teil);
            if ($z === null || isset($out[mb_strtolower($z)])) {
                continue;
            }
            $out[mb_strtolower($z)] = $z;
            if (count($out) >= $max) {
                break;
            }
        }

        return array_values($out);
    }

    private static function eintrag(string $teil): ?string
    {
        $z = str_replace(['*', '¹', '²', '³', '°'], '', $teil);
        $z = (string) preg_replace('/\d+(?:[.,]\d+)?\s*%/u', '', $z);
        if (str_contains($z, ':')) {                                    // „Säuerungsmittel: Citronensäure" → Klasse, fällt weg
            [$z] = explode(':', $z, 2);
        }
        $z = trim((string) preg_replace('/\s+/u', ' ', $z), " \t\n\r.-–");
        if (mb_strlen($z) < 3 || preg_match('/\d/u', $z) || preg_match('/^e\s?\d/iu', $z)) {
            return null;
        }
        $klein = mb_strtolower($z);
        if (in_array($klein, self::WEG, true)) {
            return null;
        }
        foreach (self::KLASSEN as $k) {
            if (str_starts_with($klein, $k) || str_ends_with($klein, $k)) {
                return null;
            }
        }
        foreach (self::ENDUNGEN as $k) {
            if (str_ends_with($klein, $k)) {
                return null;
            }
        }
        if (mb_strlen($z) > 40) {                                       // Fließtext, keine Zutat
            return null;
        }

        return $z === mb_strtoupper($z) ? mb_convert_case($klein, MB_CASE_TITLE) : $z;   // Allergen-Großschrift „MANDELN" → „Mandeln"
    }
}
