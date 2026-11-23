<?php

namespace Platform\FoodAlchemist\Services\Speiseplan;

use Illuminate\Support\Carbon;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;

/**
 * Gemeinsame Grundlagen aller Speiseplan-Teile (98 · 3.3 S3, aus SpeiseplanService verschoben): Mahlzeiten,
 * Wochentage, Öffnungstage einer Woche, effektive Essen je Eintrag und der Besitzer-Riegel. Nur, was alle Teile
 * brauchen; ohne Abhängigkeiten, ohne Zustand.
 */
final class SpeiseplanBasis
{
    public const MAHLZEITEN = ['fruehstueck' => 'Frühstück', 'mittag' => 'Mittag', 'abend' => 'Abend', 'snack' => 'Snack'];

    public const WOCHENTAGE = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    /**
     * Spec 57: effektive Essen/Portionen eines Eintrags — Eintrag-Override › Linien-Standard ›
     * Plan-Standard. Eine Stelle für Produktion, Kennzahlen, Mengen und Bedarf.
     */
    public function effektivePax(FoodAlchemistSpeiseplanEintrag $e, FoodAlchemistSpeiseplan $plan): int
    {
        if ((int) $e->pax > 0) {
            return (int) $e->pax;
        }
        $linie = $e->line_id !== null ? $plan->lines->firstWhere('id', (int) $e->line_id) : null;
        if ($linie !== null && (int) $linie->default_pax > 0) {
            return (int) $linie->default_pax;
        }

        return max(1, (int) ($plan->default_pax ?: 100));
    }

    /**
     * Spec 57 · Paket 9: die Öffnungstage einer Woche als Datumsliste (ab Montag).
     *
     * @return list<Carbon>
     */
    public function wochenTage(FoodAlchemistSpeiseplan $plan, Carbon $montag): array
    {
        $mo = $montag->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();

        return array_map(fn (int $iso) => $mo->copy()->addDays($iso - 1), $plan->oeffnungstage());
    }

    public function guard(FoodAlchemistSpeiseplan $plan, Team $team): void
    {
        if (! $plan->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Speiseplan: Ändern kann ihn nur das Besitzer-Team.');
        }
    }
}
