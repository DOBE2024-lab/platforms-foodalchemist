<?php

namespace Platform\FoodAlchemist\Services\Pruefung;

use Illuminate\Contracts\Container\Container;
use Platform\FoodAlchemist\Services\Conformance\ConformanceAdapter;
use Platform\FoodAlchemist\Services\Reife\ReifeAdapter;

/**
 * PR11 (Plan 98, Prüf-Registry, kleiner Schnitt): EINE Stelle, an der die Prüf-Adapter je Artefakt-Typ gefunden werden.
 * Bisher wählten {@see \Platform\FoodAlchemist\Services\Reife\ReifeService} und
 * {@see \Platform\FoodAlchemist\Services\Conformance\ConformanceService} ihren Adapter über ein festes `match`. Jetzt
 * steht jeder Adapter einmal in der Liste seiner Art im FoodAlchemistServiceProvider (dort getaggt und dieser Registry
 * übergeben) und meldet seine Schlüssel selbst: `artifactType()` plus `aliase()` (z. B. `vk` → Rezept). Ein Schlüssel, den zwei
 * Adapter beanspruchen, ist ein Fehler. Neuer Artefakt-Typ = Adapter schreiben + im Provider eintragen; der Wächter
 * (PruefRegistryTest) meldet jeden Adapter unter src, der fehlt. Die Listen liegen bewusst im Provider: stünden die
 * Adapter-Klassen hier, hinge die Registry im Kreis ConformanceService ↔ RecipeConformanceAdapter (Spec 98, Klinke).
 *
 * Nur lesend. Die Karte hält die registrierten Klassennamen, nicht Instanzen: jede Abfrage löst den Adapter frisch aus dem
 * Container (wie vorher `app(…)`), damit kein Zustand über Aufrufe hängen bleibt und ein in Tests gebundener Ersatz greift.
 *
 * Bewusst noch nicht hier (Kurator 2026-11-23): die Team-Detektoren (SignalDetektor, DataQuality-Emission). Die kommen
 * mit dem DataQuality-Schnitt als eigene Art dazu.
 */
final class PruefRegistry
{
    public const TAG_REIFE = 'fa.reife.adapter';

    public const TAG_CONFORMANCE = 'fa.conformance.adapter';


    /** @var array<string, class-string<ReifeAdapter>>|null */
    private ?array $reife = null;

    /** @var array<string, class-string<ConformanceAdapter>>|null */
    private ?array $conformance = null;

    /**
     * @param  list<class-string<ReifeAdapter>>  $reifeKlassen
     * @param  list<class-string<ConformanceAdapter>>  $conformanceKlassen
     */
    public function __construct(private Container $app, private array $reifeKlassen, private array $conformanceKlassen) {}

    /** @return array{reife: list<class-string>, conformance: list<class-string>} die registrierten Adapter-Klassen */
    public function klassen(): array
    {
        return ['reife' => $this->reifeKlassen, 'conformance' => $this->conformanceKlassen];
    }

    public function reifeAdapter(string $kind): ReifeAdapter
    {
        $klasse = $this->reifeKarte()[$kind] ?? throw new \InvalidArgumentException("Kein Reife-Adapter für Artefakt-Typ «{$kind}».");

        return $this->app->make($klasse);
    }

    public function conformanceAdapter(string $typ): ConformanceAdapter
    {
        $klasse = $this->conformanceKarte()[$typ] ?? throw new \InvalidArgumentException("Kein Conformance-Adapter für Artefakt-Typ «{$typ}».");

        return $this->app->make($klasse);
    }

    /** @return array{reife: array<string, class-string>, conformance: array<string, class-string>} Schlüssel → Adapter-Klasse */
    public function schluessel(): array
    {
        return ['reife' => $this->reifeKarte(), 'conformance' => $this->conformanceKarte()];
    }

    /**
     * Schlüssel → registrierte Klasse. Wirft, wenn zwei Adapter denselben Schlüssel beanspruchen.
     *
     * @param  array<class-string, ReifeAdapter|ConformanceAdapter>  $adapter  registrierte Klasse → Instanz
     * @return array<string, class-string>
     */
    public static function karte(array $adapter, string $art): array
    {
        $karte = [];
        foreach ($adapter as $klasse => $a) {
            foreach ([$a->artifactType(), ...$a->aliase()] as $schluessel) {
                if (isset($karte[$schluessel]) && $karte[$schluessel] !== $klasse) {
                    throw new \LogicException("{$art}-Schlüssel «{$schluessel}» doppelt: {$karte[$schluessel]} und {$klasse}");
                }
                $karte[$schluessel] = $klasse;
            }
        }
        ksort($karte);

        return $karte;
    }

    /** @return array<string, class-string<ReifeAdapter>> */
    private function reifeKarte(): array
    {
        return $this->reife ??= self::karte($this->instanzen($this->reifeKlassen), 'Reife');
    }

    /** @return array<string, class-string<ConformanceAdapter>> */
    private function conformanceKarte(): array
    {
        return $this->conformance ??= self::karte($this->instanzen($this->conformanceKlassen), 'Conformance');
    }

    /** @return array<class-string, object> */
    private function instanzen(array $klassen): array
    {
        return array_combine($klassen, array_map(fn (string $k) => $this->app->make($k), $klassen));
    }
}
