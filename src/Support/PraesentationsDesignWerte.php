<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Allowlist für die Raster-, Überschriften-, Titelbild- und Gerichtslisten-Einstellungen eines
 * Präsentations-Designs (2026-11-13). Gilt für UI und MCP gleich (PresentationDesignService::create/update).
 *
 * Geprüft werden nur diese Keys. Ältere Tokens bleiben unangetastet, damit Bestandsdesigns weiter speicherbar sind.
 * Fehlt ein Key oder ist er null, gilt der bisherige Look (Renderer-Default).
 */
final class PraesentationsDesignWerte
{
    /** tokens_json-Keys → erlaubte Werte. */
    public const TOKENS = [
        'page_width' => ['schmal', 'normal', 'breit'],
        'text_width' => ['schmal', 'normal', 'breit'],
        'text_align' => ['links', 'zentriert'],
    ];

    /** tokens_json.typography-Keys → erlaubte Werte. */
    public const TYPOGRAFIE = [
        'heading_size' => ['zurueckhaltend', 'normal', 'gross'],
        'heading_weight' => ['leicht', 'normal', 'kraeftig'],
        'heading_tracking' => ['eng', 'normal', 'weit'],
        'konzept_text' => ['normal', 'kursiv', 'kursiv_leise'],   // 2026-11-19: Hinweistexte im Konzept („serviert im Schraubglas“)
    ];

    /** Schalter (bool) je Block-Typ. */
    public const SCHALTER = [
        'chapter_loop' => ['show_rules', 'show_block_header', 'show_kicker', 'show_chapter_logo', 'show_kapitel_preis'],
        'dish_list' => ['show_rules', 'show_block_header'],
    ];

    public const KICKER_MAX = 120;

    /** Auswahl-Werte je Block-Typ: style-Key → erlaubte Werte (Kapitelbild-Darstellung, Kapitel-Logo). */
    public const BLOCK_WAHL = [
        'chapter_loop' => [
            'chapter_image_fit' => ['auto', 'fotoband', 'ganz'],
            'chapter_logo_position' => ['oben', 'unten'],
            'kapitel_auswahl' => KapitelAuswahl::MODI,   // 2026-11-19: alle | bereich (kapitel_von/kapitel_bis) | rest
            'konzept_preis' => ['zeile', 'betont', 'ende'],
        ],
        'dish_list' => ['konzept_preis' => ['zeile', 'betont', 'ende']],
        'price_summary' => ['preis_anzeige' => ['netto', 'brutto', 'beide']],
        'preis_aufschluesselung' => ['preis_anzeige' => ['netto', 'brutto', 'beide']],
    ];

    /** Freitexte je Block-Typ (höchstens KICKER_MAX Zeichen). */
    public const TEXTE = [
        'chapter_loop' => ['kicker_haupt', 'kicker_unter', 'kapitel_preis_label', 'konzept_preis_label'],
        'dish_list' => ['konzept_preis_label'],
        'preis_aufschluesselung' => ['titel'],
    ];

    /**
     * @throws \RuntimeException mit allen Verstößen in einer Meldung (MCP: VALIDATION_ERROR)
     */
    public static function pruefe(?array $tokens, ?array $layout): void
    {
        $fehler = [];
        foreach (self::TOKENS as $key => $erlaubt) {
            self::wahl($tokens[$key] ?? null, $erlaubt, 'tokens_json.' . $key, $fehler);
        }
        $typo = is_array($tokens['typography'] ?? null) ? $tokens['typography'] : [];
        foreach (self::TYPOGRAFIE as $key => $erlaubt) {
            self::wahl($typo[$key] ?? null, $erlaubt, 'tokens_json.typography.' . $key, $fehler);
        }

        foreach (array_values($layout ?? []) as $i => $block) {
            $typ = (string) ($block['block_type'] ?? '');
            $stil = is_array($block['style'] ?? null) ? $block['style'] : [];
            $pfad = "layout_json[{$i}].style.";
            if ($typ === 'cover') {
                $ov = $stil['overlay'] ?? null;
                if ($ov !== null && ! (is_int($ov) || (is_string($ov) && ctype_digit($ov)) || (is_float($ov) && floor($ov) === $ov))) {
                    $fehler[] = $pfad . 'overlay muss eine ganze Zahl 0–60 sein';
                } elseif ($ov !== null && ((int) $ov < 0 || (int) $ov > 60)) {
                    $fehler[] = $pfad . 'overlay muss zwischen 0 und 60 liegen';
                }
                $kt = $stil['kicker_text'] ?? null;
                if ($kt !== null && (! is_string($kt) || mb_strlen($kt) > self::KICKER_MAX)) {
                    $fehler[] = $pfad . 'kicker_text muss Text mit höchstens ' . self::KICKER_MAX . ' Zeichen sein';
                }
            }
            foreach (self::BLOCK_WAHL[$typ] ?? [] as $key => $erlaubt) {
                self::wahl($stil[$key] ?? null, $erlaubt, $pfad . $key, $fehler);
            }
            if ($typ === 'chapter_loop') {
                foreach (['kapitel_von', 'kapitel_bis'] as $key) {
                    $v = $stil[$key] ?? null;
                    if ($v !== null && $v !== '' && ! (is_int($v) || (is_string($v) && ctype_digit($v)) || (is_float($v) && floor($v) === $v))) {
                        $fehler[] = $pfad . $key . ' muss eine ganze Zahl 1–' . KapitelAuswahl::MAX_NR . ' sein';
                    } elseif ($v !== null && $v !== '' && ((int) $v < 1 || (int) $v > KapitelAuswahl::MAX_NR)) {
                        $fehler[] = $pfad . $key . ' muss zwischen 1 und ' . KapitelAuswahl::MAX_NR . ' liegen';
                    }
                }
            }
            foreach (self::TEXTE[$typ] ?? [] as $key) {
                $t = $stil[$key] ?? null;
                if ($t !== null && (! is_string($t) || mb_strlen($t) > self::KICKER_MAX)) {
                    $fehler[] = $pfad . $key . ' muss Text mit höchstens ' . self::KICKER_MAX . ' Zeichen sein';
                }
            }
            foreach (self::SCHALTER[$typ] ?? [] as $key) {
                if (array_key_exists($key, $stil) && $stil[$key] !== null && ! is_bool($stil[$key]) && ! in_array($stil[$key], [0, 1], true)) {
                    $fehler[] = $pfad . $key . ' muss true oder false sein';
                }
            }
        }

        if ($fehler !== []) {
            throw new \RuntimeException('Ungültige Design-Werte: ' . implode('; ', $fehler) . '.');
        }
    }

    /** @param list<string> $fehler */
    private static function wahl(mixed $wert, array $erlaubt, string $pfad, array &$fehler): void
    {
        if ($wert !== null && ! in_array($wert, $erlaubt, true)) {
            $fehler[] = $pfad . ' muss ' . implode('|', $erlaubt) . ' sein';
        }
    }
}
