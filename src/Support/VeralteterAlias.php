<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Log;

/**
 * Plan 98 · 3.5 Schritt 1a · Laufzeit-Meldung für die veralteten deutschen Beziehungs-Aliase (#486), bevor sie wegfallen.
 *
 * - Tests: wirft, damit die CI jeden echten Aufrufer zeigt (Textsuche überzählt, weil Spalten und Array-Schlüssel gleich heißen).
 * - Sonst (io): eine Warnung `[fa-alias]` je Alias und Prozess, mit dem ersten Aufrufer außerhalb von vendor/ und Models/.
 *   Bleibt das Log nach dem Deploy still, fallen die Aliase in Schritt 1c weg (MCP-Tools mit dynamischen `with()` wären hier sichtbar).
 */
final class VeralteterAlias
{
    /** @var array<string, true> */
    private static array $gemeldet = [];

    public static function melde(object $modell, string $alias, string $ziel): void
    {
        $text = sprintf('Veralteter Beziehungs-Alias %s::%s() — %s() nutzen (98 · 3.5).', class_basename($modell), $alias, $ziel);
        if (app()->runningUnitTests()) {
            throw new \LogicException($text);
        }
        $schluessel = $modell::class.'::'.$alias;
        if (isset(self::$gemeldet[$schluessel])) {
            return;
        }
        self::$gemeldet[$schluessel] = true;
        $aufrufer = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $f) {
            $datei = $f['file'] ?? '';
            if ($datei !== '' && ! str_contains($datei, '/vendor/laravel/') && ! str_contains($datei, '/vendor/livewire/')
                && ! str_contains($datei, '/src/Models/') && ! str_ends_with($datei, '/VeralteterAlias.php')) {
                $aufrufer = $datei.':'.($f['line'] ?? 0);
                break;
            }
        }
        Log::warning('[fa-alias] '.$text, ['aufrufer' => $aufrufer]);
    }
}
