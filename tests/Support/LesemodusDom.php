<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * Spec 65 · Lesemodus-Anzeige (Plan 98): prüft im gerenderten HTML, ob ein Element unter einem deaktivierten fieldset liegt.
 * Genau das sperrt der Browser (alle Knöpfe und Eingaben darunter, verschachtelte Livewire-Kinder eingeschlossen). Eigene Klasse,
 * weil Test-Helfer im Parallel-Lauf nicht als globale Funktion in einer Testdatei liegen dürfen.
 */
final class LesemodusDom
{
    /**
     * Jedes fieldset muss die Tags in sich schließen. Ein </fieldset>, das ein offenes <div> der Umgebung schließt (Sperre über eine
     * Wrapper-Grenze geteilt), bricht im Browser das Layout und lässt libxml falsch verschachteln. @return list<string> Befunde
     */
    public static function sperrenUnbalanciert(string $html): array
    {
        $html = (string) preg_replace('/<!--.*?-->|<script\b.*?<\/script>|<svg\b.*?<\/svg>/is', '', $html);
        $leer = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'path'];
        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?(\/?)>/', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $stapel = [];
        $befunde = [];
        foreach ($m as $t) {
            $name = strtolower($t[2][0]);
            if (in_array($name, $leer, true) || $t[3][0] === '/') {
                continue;
            }
            if ($t[1][0] === '') {
                $stapel[] = [$name, $t[0][1]];
                continue;
            }
            if ($name !== 'fieldset') {
                if ($stapel !== [] && end($stapel)[0] === $name) {
                    array_pop($stapel);
                }
                continue;
            }
            while ($stapel !== [] && end($stapel)[0] !== 'fieldset') {
                [$offen, $pos] = array_pop($stapel);
                $befunde[] = "<{$offen}> bei ".$pos.' im fieldset nicht geschlossen: '.mb_substr(substr($html, $pos, 90), 0, 90);
            }
            array_pop($stapel);
        }

        return $befunde;
    }

    /** @return list<bool> je Treffer des XPath: true = ein Vorfahr ist fieldset[@disabled] */
    public static function gesperrt(string $html, string $xpath): array
    {
        self::strukturPruefen($html);
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

    /**
     * @return list<bool> je Treffer: true = im Browser bedienbar. Ein Formular-Element (button, input, select, textarea, fieldset)
     * unter fieldset[disabled] ist es nicht; ein <a role="button"> (x-fa::anzeige-knopf) bleibt es auch dort.
     */
    public static function bedienbar(string $html, string $xpath): array
    {
        self::strukturPruefen($html);
        $dom = new \DOMDocument;
        $alt = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);

        $x = new \DOMXPath($dom);
        $ergebnis = [];
        foreach ($x->query($xpath) as $knoten) {
            $formular = in_array(strtolower($knoten->nodeName), ['button', 'input', 'select', 'textarea'], true);
            $ergebnis[] = ! $formular || $x->query('ancestor::fieldset[@disabled]', $knoten)->length === 0;
        }

        return $ergebnis;
    }

    private static function strukturPruefen(string $html): void
    {
        $befunde = self::sperrenUnbalanciert($html);
        if ($befunde !== []) {
            throw new \RuntimeException("Lese-Sperre über eine Tag-Grenze geteilt:\n".implode("\n", $befunde));
        }
    }

    /** Simuliert die Sperre des Eltern-Editors um ein eingebettetes Livewire-Kind (Kinder rendern im Test einzeln). */
    public static function inGesperrtemRahmen(string $html): string
    {
        return '<fieldset disabled class="contents">'.$html.'</fieldset>';
    }

    /** Bedienelemente (button, input, select, textarea) unter einer Anzeige-Insel (data-fa-anzeige), die gesperrt sind. */
    public static function gesperrteAnzeigeBedienung(string $html): int
    {
        return count(array_filter(self::gesperrt($html, '//*[@data-fa-anzeige]/descendant-or-self::*[self::button or self::input or self::select or self::textarea]')));
    }
}
