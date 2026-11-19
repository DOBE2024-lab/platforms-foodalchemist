<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Auswahl für „Zubehör & Verpackung“ an der Darreichung (19.11.2026, Dominique: „ich kann da nichts suchen, ich weiß nicht, was man eingeben muss“).
 *
 * Nur Non-Food, das an ein Gericht gehört: Einweg & Verpackung, Deko & Tischkultur, Geschirr & Ausstattung. Reinigung, Arbeitskleidung,
 * Küchenbedarf und Büro tauchen nie auf; Non-Food ohne Unterkategorie steht am Ende („ohne Unterkategorie“), damit Neues nicht verschwindet.
 * Ohne Suchtext liefert sie eine Liste zum Durchblättern (beim Klick ins Feld), mit Suchtext die Treffer.
 * Der Aufrufer übergibt die SICHTBARE Abfrage (`FoodAlchemistGp::visibleToTeam($team)`) — die Sichtprüfung bleibt an der Aufrufstelle.
 */
final class ZubehoerAuswahl
{
    /** Unterkategorien, die als Zubehör an ein Gericht gehen können — in Anzeige-Reihenfolge. */
    public const UNTERKATEGORIEN = [NonFoodRegeln::SUB_EINWEG, NonFoodRegeln::SUB_DEKO, NonFoodRegeln::SUB_GESCHIRR];

    public const LIMIT_LISTE = 200;

    public const LIMIT_SUCHE = 12;

    public const OHNE = 'ohne Unterkategorie';

    /**
     * @return Collection<int, object{id:int, name:string, gruppe:string}>
     */
    public static function treffer(Builder $sichtbar, ?string $suche): Collection
    {
        $text = trim((string) $suche);
        $subs = [];
        foreach (self::UNTERKATEGORIEN as $sub) {
            foreach (NonFoodRegeln::mitAlias($sub) as $variante) {
                $subs[$variante] = $sub;
            }
        }
        $q = $sichtbar->nonFood()->whereNotIn('status', ['rejected', 'merged'])
            ->where(fn ($w) => $w->whereIn('sub_category', array_keys($subs))->orWhereNull('sub_category')->orWhere('sub_category', ''));
        if ($text !== '') {
            $q = Suche::like($q, 'name', $text);
        }
        $reihe = array_flip(self::UNTERKATEGORIEN);

        return $q->orderBy('name')->limit($text !== '' ? self::LIMIT_SUCHE : self::LIMIT_LISTE)->get(['id', 'name', 'sub_category'])
            ->map(fn ($gp) => (object) ['id' => (int) $gp->id, 'name' => (string) $gp->name, 'gruppe' => $subs[(string) $gp->sub_category] ?? self::OHNE])
            ->sortBy(fn ($t) => sprintf('%02d %s', $reihe[$t->gruppe] ?? 99, mb_strtolower($t->name)))->values();
    }

    /** Anzeigename der Gruppe ohne Nummer („16.2 Einweg & Verpackung“ → „Einweg & Verpackung“). */
    public static function gruppenName(string $gruppe): string
    {
        return trim((string) preg_replace('/^\d+(\.\d+)?\s*/', '', $gruppe));
    }
}
