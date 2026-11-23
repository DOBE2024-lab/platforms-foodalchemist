<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * Spec 65 · Lesemodus-Anzeige (Plan 98): prüft im gerenderten HTML, ob ein Element unter einem deaktivierten fieldset liegt.
 * Genau das sperrt der Browser (alle Knöpfe und Eingaben darunter, verschachtelte Livewire-Kinder eingeschlossen). Eigene Klasse,
 * weil Test-Helfer im Parallel-Lauf nicht als globale Funktion in einer Testdatei liegen dürfen.
 */
final class LesemodusDom
{
    /** @return list<bool> je Treffer des XPath: true = ein Vorfahr ist fieldset[@disabled] */
    public static function gesperrt(string $html, string $xpath): array
    {
        $dom = new \DOMDocument;
        $alt = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);

        $ergebnis = [];
        foreach ((new \DOMXPath($dom))->query($xpath) as $knoten) {
            $ergebnis[] = (new \DOMXPath($dom))->query('ancestor::fieldset[@disabled]', $knoten)->length > 0;
        }

        return $ergebnis;
    }

    /** Bedienelemente (button, input, select, textarea) unter einer Anzeige-Insel (data-fa-anzeige), die gesperrt sind. */
    public static function gesperrteAnzeigeBedienung(string $html): int
    {
        return count(array_filter(self::gesperrt($html, '//*[@data-fa-anzeige]/descendant-or-self::*[self::button or self::input or self::select or self::textarea]')));
    }
}
