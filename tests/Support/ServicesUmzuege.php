<?php

namespace Platform\FoodAlchemist\Tests\Support;

/**
 * 98 · 3.8 Ordnung: Karte der aus dem Root von src/Services in Fachordner verschobenen Dienste (alter Pfad → neuer Pfad,
 * relativ zu src). Schreibt nur scripts/services-umzug.php; nie von Hand pflegen.
 *
 * Wozu: Baselines, die nach Pfad schlüsseln (Abhängigkeits-Klinke), sehen eine verschobene Datei sonst als neu (0 → n).
 * Mit dieser Karte zählt der neue Pfad bei der Quelle (BaselineKlinke::gestiegen, Umzugs-Parameter). Dazu prüft
 * ServicesOrdnungTest, dass kein Code mehr den alten Klassennamen nutzt.
 */
final class ServicesUmzuege
{
    /** @var array<string, string> 'Services/X.php' => 'Services/Ordner/X.php' */
    public const KARTE = [
        'Services/AboAuskunft.php' => 'Services/Plattform/AboAuskunft.php',
        'Services/AboKuendigungService.php' => 'Services/Plattform/AboKuendigungService.php',
        'Services/AboService.php' => 'Services/Plattform/AboService.php',
        'Services/ActiveOutletContext.php' => 'Services/Plattform/ActiveOutletContext.php',
        'Services/BearbeitungssperreService.php' => 'Services/Plattform/BearbeitungssperreService.php',
        'Services/BildWaisenService.php' => 'Services/Plattform/BildWaisenService.php',
        'Services/BulkEnrichService.php' => 'Services/Plattform/BulkEnrichService.php',
        'Services/BulkRunStatusService.php' => 'Services/Plattform/BulkRunStatusService.php',
        'Services/DuplikatBilderService.php' => 'Services/Plattform/DuplikatBilderService.php',
        'Services/ExportAuskunft.php' => 'Services/Plattform/ExportAuskunft.php',
        'Services/FoodAlchemistMediaService.php' => 'Services/Plattform/FoodAlchemistMediaService.php',
        'Services/GebuehrenZaehler.php' => 'Services/Plattform/GebuehrenZaehler.php',
        'Services/IngestStatusService.php' => 'Services/Plattform/IngestStatusService.php',
        'Services/KpiService.php' => 'Services/Plattform/KpiService.php',
        'Services/MandantBeendenService.php' => 'Services/Plattform/MandantBeendenService.php',
        'Services/MandantExportService.php' => 'Services/Plattform/MandantExportService.php',
        'Services/MandantenService.php' => 'Services/Plattform/MandantenService.php',
        'Services/MandantenStammblattService.php' => 'Services/Plattform/MandantenStammblattService.php',
        'Services/MasterTeamService.php' => 'Services/Plattform/MasterTeamService.php',
        'Services/OutletService.php' => 'Services/Plattform/OutletService.php',
        'Services/OutletSettingsService.php' => 'Services/Plattform/OutletSettingsService.php',
        'Services/PlanNutzungService.php' => 'Services/Plattform/PlanNutzungService.php',
        'Services/PlanPreisService.php' => 'Services/Plattform/PlanPreisService.php',
        'Services/PlattformAdminPruefung.php' => 'Services/Plattform/PlattformAdminPruefung.php',
        'Services/PlattformProtokoll.php' => 'Services/Plattform/PlattformProtokoll.php',
        'Services/StandortService.php' => 'Services/Plattform/StandortService.php',
        'Services/SupportFreigabeService.php' => 'Services/Plattform/SupportFreigabeService.php',
        'Services/TeamAnlegenService.php' => 'Services/Plattform/TeamAnlegenService.php',
        'Services/TeamProfilService.php' => 'Services/Plattform/TeamProfilService.php',
        'Services/VoiceCommandService.php' => 'Services/Plattform/VoiceCommandService.php',
        'Services/VoiceSessionService.php' => 'Services/Plattform/VoiceSessionService.php',
        'Services/WorkerHealthService.php' => 'Services/Plattform/WorkerHealthService.php',
    ];

    /** @return array<string, string> neuer Pfad => alter Pfad (Form, die BaselineKlinke::gestiegen erwartet) */
    public static function zielZuQuelle(): array
    {
        return array_flip(self::KARTE);
    }

    /** @return array<string, string> alter voller Klassenname => neuer voller Klassenname */
    public static function klassen(): array
    {
        $fqn = fn (string $pfad) => 'Platform\\FoodAlchemist\\'.str_replace('/', '\\', substr($pfad, 0, -4));
        $out = [];
        foreach (self::KARTE as $alt => $neu) {
            $out[$fqn($alt)] = $fqn($neu);
        }

        return $out;
    }
}
