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
        'Services/AngebotEinfrierService.php' => 'Services/Ausgabe/AngebotEinfrierService.php',
        'Services/AngebotKopieService.php' => 'Services/Ausgabe/AngebotKopieService.php',
        'Services/AngebotService.php' => 'Services/Ausgabe/AngebotService.php',
        'Services/BearbeitungssperreService.php' => 'Services/Plattform/BearbeitungssperreService.php',
        'Services/BehaelterBedarfService.php' => 'Services/Produktion/BehaelterBedarfService.php',
        'Services/BehaelterRechner.php' => 'Services/Produktion/BehaelterRechner.php',
        'Services/BenchmarkService.php' => 'Services/Trend/BenchmarkService.php',
        'Services/BildWaisenService.php' => 'Services/Plattform/BildWaisenService.php',
        'Services/BulkEnrichService.php' => 'Services/Plattform/BulkEnrichService.php',
        'Services/BulkRunStatusService.php' => 'Services/Plattform/BulkRunStatusService.php',
        'Services/DerivatAusbeuteService.php' => 'Services/Produktion/DerivatAusbeuteService.php',
        'Services/DruckerService.php' => 'Services/Ausgabe/DruckerService.php',
        'Services/DuplikatBilderService.php' => 'Services/Plattform/DuplikatBilderService.php',
        'Services/EigenproduktionService.php' => 'Services/Produktion/EigenproduktionService.php',
        'Services/EquipmentStandardService.php' => 'Services/Produktion/EquipmentStandardService.php',
        'Services/EtikettService.php' => 'Services/Ausgabe/EtikettService.php',
        'Services/ExportAuskunft.php' => 'Services/Plattform/ExportAuskunft.php',
        'Services/FixkostenService.php' => 'Services/Produktion/FixkostenService.php',
        'Services/FoodAlchemistMediaService.php' => 'Services/Plattform/FoodAlchemistMediaService.php',
        'Services/FoodbookService.php' => 'Services/Foodbook/FoodbookService.php',
        'Services/GebuehrenZaehler.php' => 'Services/Plattform/GebuehrenZaehler.php',
        'Services/GefaessKostenartService.php' => 'Services/Produktion/GefaessKostenartService.php',
        'Services/GefaessZubehoerService.php' => 'Services/Produktion/GefaessZubehoerService.php',
        'Services/HandbuchPositionsMenge.php' => 'Services/Ausgabe/HandbuchPositionsMenge.php',
        'Services/HandbuchQuellenService.php' => 'Services/Ausgabe/HandbuchQuellenService.php',
        'Services/HandbuchService.php' => 'Services/Ausgabe/HandbuchService.php',
        'Services/HandbuchZutatenService.php' => 'Services/Ausgabe/HandbuchZutatenService.php',
        'Services/HandyDetailService.php' => 'Services/Ausgabe/HandyDetailService.php',
        'Services/IngestStatusService.php' => 'Services/Plattform/IngestStatusService.php',
        'Services/KapitelLogoService.php' => 'Services/Foodbook/KapitelLogoService.php',
        'Services/KnowledgeRoutingService.php' => 'Services/Knowledge/KnowledgeRoutingService.php',
        'Services/KnowledgeService.php' => 'Services/Knowledge/KnowledgeService.php',
        'Services/KonfiguratorPricingService.php' => 'Services/Konfigurator/KonfiguratorPricingService.php',
        'Services/KonfiguratorService.php' => 'Services/Konfigurator/KonfiguratorService.php',
        'Services/KpiService.php' => 'Services/Plattform/KpiService.php',
        'Services/LagebildService.php' => 'Services/Trend/LagebildService.php',
        'Services/MandantBeendenService.php' => 'Services/Plattform/MandantBeendenService.php',
        'Services/MandantExportService.php' => 'Services/Plattform/MandantExportService.php',
        'Services/MandantenService.php' => 'Services/Plattform/MandantenService.php',
        'Services/MandantenStammblattService.php' => 'Services/Plattform/MandantenStammblattService.php',
        'Services/MarktEinordnungService.php' => 'Services/Trend/MarktEinordnungService.php',
        'Services/MarktdatenAnsicht.php' => 'Services/Trend/MarktdatenAnsicht.php',
        'Services/MarktdatenService.php' => 'Services/Trend/MarktdatenService.php',
        'Services/MasterTeamService.php' => 'Services/Plattform/MasterTeamService.php',
        'Services/MenuEngineeringService.php' => 'Services/Ausgabe/MenuEngineeringService.php',
        'Services/ObservatoriumBriefingService.php' => 'Services/Trend/ObservatoriumBriefingService.php',
        'Services/ObservatoriumPinnwandService.php' => 'Services/Trend/ObservatoriumPinnwandService.php',
        'Services/ObservatoriumService.php' => 'Services/Trend/ObservatoriumService.php',
        'Services/OfferCompositionService.php' => 'Services/Ausgabe/OfferCompositionService.php',
        'Services/OutletService.php' => 'Services/Plattform/OutletService.php',
        'Services/OutletSettingsService.php' => 'Services/Plattform/OutletSettingsService.php',
        'Services/PairingInspirationService.php' => 'Services/Pairing/PairingInspirationService.php',
        'Services/PairingService.php' => 'Services/Pairing/PairingService.php',
        'Services/PlanNutzungService.php' => 'Services/Plattform/PlanNutzungService.php',
        'Services/PlanPreisService.php' => 'Services/Plattform/PlanPreisService.php',
        'Services/PlattformAdminPruefung.php' => 'Services/Plattform/PlattformAdminPruefung.php',
        'Services/PlattformProtokoll.php' => 'Services/Plattform/PlattformProtokoll.php',
        'Services/PortfolioService.php' => 'Services/Foodbook/PortfolioService.php',
        'Services/PresentationDesignService.php' => 'Services/Ausgabe/PresentationDesignService.php',
        'Services/PresentationFontService.php' => 'Services/Ausgabe/PresentationFontService.php',
        'Services/PresentationService.php' => 'Services/Ausgabe/PresentationService.php',
        'Services/PresentationShareCardService.php' => 'Services/Ausgabe/PresentationShareCardService.php',
        'Services/ProductionCapacityService.php' => 'Services/Produktion/ProductionCapacityService.php',
        'Services/ProductionEventService.php' => 'Services/Produktion/ProductionEventService.php',
        'Services/ProductionOrderService.php' => 'Services/Produktion/ProductionOrderService.php',
        'Services/ProductionPlanService.php' => 'Services/Produktion/ProductionPlanService.php',
        'Services/ProductionReadinessService.php' => 'Services/Produktion/ProductionReadinessService.php',
        'Services/ProductionStationService.php' => 'Services/Produktion/ProductionStationService.php',
        'Services/ProductionTimeService.php' => 'Services/Produktion/ProductionTimeService.php',
        'Services/ProduktionListeService.php' => 'Services/Produktion/ProduktionListeService.php',
        'Services/ProduktionsVerbrauchService.php' => 'Services/Produktion/ProduktionsVerbrauchService.php',
        'Services/RegenerationCascadeService.php' => 'Services/Produktion/RegenerationCascadeService.php',
        'Services/ReportExportService.php' => 'Services/Ausgabe/ReportExportService.php',
        'Services/SpeisekarteLeitstelleService.php' => 'Services/Ausgabe/SpeisekarteLeitstelleService.php',
        'Services/SpeisekarteService.php' => 'Services/Ausgabe/SpeisekarteService.php',
        'Services/SpeiseplanService.php' => 'Services/Speiseplan/SpeiseplanService.php',
        'Services/SpeiseplanVorgabenService.php' => 'Services/Speiseplan/SpeiseplanVorgabenService.php',
        'Services/StandortService.php' => 'Services/Plattform/StandortService.php',
        'Services/StationLaborRateService.php' => 'Services/Produktion/StationLaborRateService.php',
        'Services/SupportFreigabeService.php' => 'Services/Plattform/SupportFreigabeService.php',
        'Services/SurplusToDishService.php' => 'Services/Produktion/SurplusToDishService.php',
        'Services/TeamAnlegenService.php' => 'Services/Plattform/TeamAnlegenService.php',
        'Services/TeamProfilService.php' => 'Services/Plattform/TeamProfilService.php',
        'Services/TrendEingangService.php' => 'Services/Trend/TrendEingangService.php',
        'Services/TrendQuellenService.php' => 'Services/Trend/TrendQuellenService.php',
        'Services/TrendService.php' => 'Services/Trend/TrendService.php',
        'Services/TrendSignalService.php' => 'Services/Trend/TrendSignalService.php',
        'Services/TrendThemenService.php' => 'Services/Trend/TrendThemenService.php',
        'Services/TrendVertiefungService.php' => 'Services/Trend/TrendVertiefungService.php',
        'Services/VoiceCommandService.php' => 'Services/Plattform/VoiceCommandService.php',
        'Services/VoiceSessionService.php' => 'Services/Plattform/VoiceSessionService.php',
        'Services/WettbewerbService.php' => 'Services/Trend/WettbewerbService.php',
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
