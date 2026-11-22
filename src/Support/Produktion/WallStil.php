<?php

namespace Platform\FoodAlchemist\Support\Produktion;

/**
 * Küchenmonitor-Stil (Fernsicht, Touch): die Klassen-Strings, die Tagesplan-Wall und ihre Kinder (Anleitung, Feedback,
 * Wall) gemeinsam nutzen — unverändert aus dem @php-Kopf von produktion/tagesplan.blade.php (98 · 3.3 S6). Blade-@include
 * gibt keine Variablen zurück; eine Kopie je View hätte die Klassen verdoppelt.
 *
 * Tailwind findet die Klassen hier: Modul-Build scannt src/**\/*.php (build-css.mjs), der Host food-alchemist.de per
 * `@source "../../vendor/martin3r/platform-foodalchemist/src/**\/*.php"`. UiRegeln zählt diese Datei ausdrücklich mit.
 */
final class WallStil
{
    public const KNOPF = 'inline-flex h-12 items-center justify-center gap-2 rounded-xl border border-[var(--fa-line-strong)] bg-[var(--fa-surface)] px-4 text-base font-semibold text-[var(--fa-ink)] transition-colors hover:bg-[var(--fa-hover)]';

    public const EYEBROW = 'text-sm font-semibold text-[var(--fa-ink-3)]';

    public const KARTE = 'rounded-2xl border border-[var(--fa-line)] bg-[var(--fa-surface)]';

    public const SICHER = 'inline-flex items-center rounded-full px-2.5 py-1 text-sm font-semibold';

    public const HAKEN_OFFEN = 'border-dashed border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] hover:bg-[var(--fa-accent-soft-hover)]';

    public const HAKEN_FERTIG = 'border-[var(--fa-ok)] bg-[var(--fa-ok)] text-[var(--fa-ground)]';

    public const TON_FLAECHE = [
        'crit' => 'bg-[var(--fa-crit-soft)] text-[var(--fa-crit)]',
        'warn' => 'bg-[var(--fa-warn-soft)] text-[var(--fa-warn)]',
        'ok' => 'bg-[var(--fa-ok-soft)] text-[var(--fa-ok)]',
        'info' => 'bg-[var(--fa-info-soft)] text-[var(--fa-info)]',
        'neutral' => 'bg-[var(--fa-neutral-soft)] text-[var(--fa-ink-3)]',
    ];

    /** Kilogramm kompakt: 3 Nachkommastellen, Nullen weg („1,5“ statt „1,500“). */
    public static function kg($wert): string
    {
        return rtrim(rtrim(number_format((float) $wert, 3, ',', '.'), '0'), ',');
    }
}
