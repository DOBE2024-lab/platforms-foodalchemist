<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;

/**
 * Steuerklasse (Entscheid Dominique 16.11.2026): GP und Lieferantenartikel tragen `vat_profile_key` (regulaer | ermaessigt | ohne)
 * statt einer festen Zahl; der Prozentsatz kommt aus den Team-Einstellungen ({@see TeamSettingsService::mwst}).
 *
 * Reihenfolge der Klasse für einen Artikel: eigene Klasse am Artikel → Klasse des GP → Regel aus der Warengruppe/Ware.
 * Die Necta-Zahl `supplier_items.vat` ist nur Lieferantenangabe zum Abgleich ({@see nectaKlasse}).
 */
class SteuerklasseService
{
    public const KLASSEN = ['regulaer', 'ermaessigt', 'ohne'];

    public const LABELS = ['regulaer' => 'regulär', 'ermaessigt' => 'ermäßigt', 'ohne' => 'ohne MwSt'];

    /** Getränke (WG 15), die nach UStG Anlage 2 ermäßigt sind (Regel 17 v1, Georg 16.11.2026): Milch und Milcherzeugnisse zum Trinken (Nr. 35),
     *  Kaffee, Tee, Kakao als Ware inkl. Kapseln/Pulver (Nr. 12), Sirup und Getränkepulver (Nr. 33), Speiseessig (Nr. 36). */
    private const GETRAENK_ERMAESSIGT = '/(milch|buttermilch|kefir|trinkjoghurt|joghurtdrink|sirup|getränkepulver|essig)/iu';

    /** Kaffee, Tee, Kakao nur als Ware (trocken, Pulver, Kapsel, Pad, Bohne, Beutel) — fertige Tee-/Kaffeegetränke (Eistee) sind regulär. */
    private const GETRAENK_WARE = '/(kaffee|espresso|cappuccino|tee|matcha|\\bmate\\b|rooibos|kakao)/iu';

    private const WARE_FORM = '/(trocken|pulver|kapsel|\\bpad\\b|bohne|gemahlen|beutel|\\blose\\b|löslich)/iu';

    /** Getränke, die auch mit Milch-/Kaffee-Wort regulär bleiben: Milchmischgetränke ohne belegten Milchanteil ≥ 75 % (Nr. 35) und Pflanzendrinks. */
    private const GETRAENK_REGULAER_VORRANG = '/(milchmisch|(hafer|soja|mandel|reis|erbsen|kokos|cashew|dinkel|haselnuss)drink)/iu';

    /** Alkohol (auch als Kochzutat, Regel 17) — Erkennung über vol-% oder Gattung. */
    private const ALKOHOL = '/((?<!sch)wein\\b|\\b(weinbrand|cognac|brandy|rum|whisky|whiskey|grappa|likör|sherry|portwein|madeira|marsala|banyuls|sake|calvados|wodka|vodka|gin|kirschwasser|obstbrand|bier)\\b)/iu';

    /** Lebensmittel, die nicht in Anlage 2 stehen (Regel 17; ergänzt 16.11.2026 um eindeutige Fälle: Lebensmittelfarben, Xanthan/Gellan/
     *  Lecithin/Methylcellulose/Maltodextrin (Kap. 29/35/39), Enzyme, Glitzerpuder, Kakaobutter/-bohnen/-nibs — Kap. 18 außer Kakaopulver —, Mirin;
     *  Mischungen wie Geliermittel/Stabilisatoren (2106) und Agar/Carrageen/Johannisbrot (1302) bleiben bis zur Klärung ermäßigt): Hummer, Langusten, Austern, Schnecken, Kaviar und Kaviar-Ersatz (Nr. 3, 28),
     *  Frucht- und Gemüsesäfte, Nektar, Smoothie (Nr. 32), reine Zusatz-Chemikalien, Pflanzendrinks. */
    private const REGULAER_WARE = '/(\b(hummer|languste|langusten|auster|austern|schnecke|schnecken|ascorbinsäure|calciumchlorid|calciumlactat|natriumalginat|natriumcitrat|zitronensäure|weinsäure)\b|lebensmittelfarbe|gellan|xanthan|agar[- ]?agar|lecithin|methylcellulose|invertase|glitzer|gummi arabicum|orangenblütenwasser|kakaobutter|kakaobohne|kakaonibs|kakaomasse|\bmirin\b|\bsake\b|shaoxing|johannisbrotkern|guarkern|\beiklar|lavendel|süßholz|verjus|zitronengras|tonkabohne|\bmaca\b|holunderblüte|hummerkarkasse|\bnatron\b|zitronenaroma|vanillearoma|\bkaviar\b(?!-)|(?<!braten|dick)saft\b|nektar\b|\bsmoothie\b(?!-)|(hafer|soja|mandel|reis|erbsen|cashew|dinkel|haselnuss)drink)/iu';

    public function __construct(private readonly TeamSettingsService $settings) {}

    /** Regel-Vorschlag für ein GP aus Warengruppe und Name (ohne gespeicherte Klasse). */
    public function regelFuerGp(object $gp): string
    {
        $wg = (string) ($gp->commodity_group_code ?? '');
        $name = (string) ($gp->name ?? '');
        // Ware = Hauptzutat bzw. Namenskopf vor dem Doppelpunkt; Geschmack/Sorte dahinter („Dip: Bier“, „Popcorn: Whisky“) entscheidet nicht
        $kopf = trim((string) (($gp->main_ingredient_display ?? '') !== '' ? $gp->main_ingredient_display : strtok($name, ':')));
        $alkohol = preg_match('/vol-%/u', $name) === 1 || preg_match(self::ALKOHOL, $kopf) === 1;

        if (\Platform\FoodAlchemist\Support\WgRolle::istNonFood($wg)) {
            return 'regulaer';
        }
        if (\Platform\FoodAlchemist\Support\WgRolle::istGetraenk($wg)) {
            if ($alkohol || preg_match(self::GETRAENK_REGULAER_VORRANG, $kopf) === 1) {
                return 'regulaer';
            }
            if (preg_match(self::GETRAENK_ERMAESSIGT, $kopf) === 1) {
                return 'ermaessigt';
            }

            return preg_match(self::GETRAENK_WARE, $kopf) === 1 && preg_match(self::WARE_FORM, $name) === 1 ? 'ermaessigt' : 'regulaer';
        }
        if ($alkohol) {
            return 'regulaer';
        }
        // Regel 17 Teil 2 (Georg 16.11.2026): Süßkartoffel, Maniok, Yams, Taro roh/frisch/TK regulär (0714, Nr. 10 nur Topinambur), zubereitet ermäßigt;
        // getrocknete Algen regulär (Nr. 22 nimmt Algen aus), Algensalat ermäßigt
        if ($wg === '01' && preg_match('/(süßkartoffel|suesskartoffel|maniok|\\byams\\b|\\btaro\\b)/iu', $kopf) === 1
            && preg_match('/(pommes|püree|gegart|frittiert|chips|gebacken)/iu', $name) !== 1) {
            return 'regulaer';
        }
        if (preg_match('/(dulse|kombu|\\bnori\\b|wakame|alge\\b|algen\\b)/iu', $kopf) === 1 && preg_match('/trocken/iu', $name) === 1
            && preg_match('/salat/iu', $name) !== 1) {
            return 'regulaer';
        }
        // Teil 2 v2 (Georg 16.11.2026): Soja-Kochcreme (Alpro Cuisine) regulär nach Rechnung
        if (preg_match('/sahne-alternative/iu', $kopf) === 1 && preg_match('/soja/iu', $name) === 1) {
            return 'regulaer';
        }
        // Teigwaren, auch gefüllt (Anlage 2 Nr. 31), bleiben ermäßigt — auch mit Hummer-Füllung
        if ($wg !== '08' && preg_match(self::REGULAER_WARE, $kopf) === 1) {
            return 'regulaer';
        }

        return 'ermaessigt';
    }

    /** Klasse eines GP: gespeichert, sonst Regel. */
    public function klasseGp(object $gp): string
    {
        $k = $gp->vat_profile_key ?? null;

        return in_array($k, self::KLASSEN, true) ? $k : $this->regelFuerGp($gp);
    }

    /** Klasse eines Artikels: eigene Klasse, sonst die des GP, sonst aus der Necta-Zahl, sonst ermäßigt. */
    public function klasseLa(object $la, ?object $gp = null): string
    {
        $k = $la->vat_profile_key ?? null;
        if (in_array($k, self::KLASSEN, true)) {
            return $k;
        }
        if ($gp !== null) {
            return $this->klasseGp($gp);
        }

        return $this->nectaKlasse($la->vat ?? null) ?? 'ermaessigt';
    }

    /** Prozentsatz einer Klasse aus den Team-Einstellungen. */
    public function satz(Team $team, string $klasse): float
    {
        if ($klasse === 'ohne') {
            return 0.0;
        }
        $m = $this->settings->mwst($team);

        return (float) ($m[$klasse] ?? $m['ermaessigt']);
    }

    /** Necta-Zahl → Klasse (nur deutsche Sätze 7/19, sonst null = nicht zuordenbar, z. B. 10 % Österreich). */
    public function nectaKlasse(mixed $vat): ?string
    {
        if ($vat === null || $vat === '') {
            return null;
        }
        $v = round((float) $vat, 2);

        return match (true) {
            $v === 19.0 => 'regulaer',
            $v === 7.0 => 'ermaessigt',
            $v === 0.0 => 'ohne',
            default => null,
        };
    }

    /** Widerspricht die Necta-Zahl der wirksamen Klasse? (null = keine Necta-Angabe) */
    public function nectaWiderspruch(object $la, ?object $gp = null): ?bool
    {
        if (($la->vat ?? null) === null || $la->vat === '') {
            return null;
        }
        $n = $this->nectaKlasse($la->vat);

        return $n === null || $n !== $this->klasseLa($la, $gp);
    }
}
