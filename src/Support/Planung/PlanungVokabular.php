<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment;
use Platform\FoodAlchemist\Models\FoodAlchemistEventtyp;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistServiceArt;

/**
 * Code-Qualität 2.5b · Auswahl der Planung für Saison, Anlass (= Einsatzmoment), Eventtyp und Service-Art aus den DB-Vokabularen
 * (Einstellungen › Konzepter-Dimensionen) statt aus Konstanten. Gespeichert wird der Code ({@see VokabularCodes}).
 * Je Anfrage einmal gelesen (Memo je Team).
 */
final class PlanungVokabular
{
    /** Feld der Planung => Modell. */
    public const MODELLE = [
        'saison' => FoodAlchemistSaison::class,
        'occasion' => FoodAlchemistEinsatzmoment::class,
        'eventtyp' => FoodAlchemistEventtyp::class,
        'serviceform' => FoodAlchemistServiceArt::class,
    ];

    /** Alte Werte, die heute in einem anderen Feld liegen (Dominique 22.11.2026: „Konferenz“ ist ein Eventtyp, kein Anlass). */
    public const ALIASE = [
        'occasion' => ['konferenz' => ['feld' => 'eventtyp', 'code' => 'konferenz']],
    ];

    /** @var array<string, array<string, string>> */
    private array $memo = [];

    /**
     * Code => Name, nach Sortierung, gleicher Code nur einmal. Mit Rückfall (Kurator 22.11.2026), damit ein Team ohne eigene Kopien
     * (neues Team, Test-DB) gültige Werte wie `lunch` nicht still verliert: zuerst was das Team sieht (eigene + geerbte + globale
     * Zeilen), sonst der Grundbestand im Code ({@see VokabularCodes::GRUNDBESTAND}). Basis-Zeilen mit `team_id` NULL gibt es für
     * Saison/Einsatzmoment/Eventtyp nicht (io-Zählung 22.11.: jedes Team hat eigene, identische Kopien); Service-Art liegt global.
     */
    public function optionen(?Team $team, string $feld): array
    {
        $modell = self::MODELLE[$feld] ?? null;
        if ($team === null || $modell === null) {
            return [];
        }

        return $this->memo[$team->id . ':' . $feld] ??= $this->ausZeilen($modell::visibleToTeam($team))
            ?: self::grundbestand((new $modell)->getTable());
    }

    /** @return array<string, string> */
    private function ausZeilen($query): array
    {
        return $query->where('is_inactive', false)->whereNotNull('code')
            ->orderBy('sort_order')->orderBy('name')->get(['code', 'name'])
            ->unique('code')->mapWithKeys(fn ($z) => [(string) $z->code => (string) $z->name])->all();
    }

    /** @return array<string, string> */
    private static function grundbestand(string $tabelle): array
    {
        $out = [];
        foreach (VokabularCodes::GRUNDBESTAND[$tabelle] ?? [] as $name) {
            $out[VokabularCodes::codeFuer($tabelle, $name)] = $name;
        }

        return $out;
    }

    public function gueltig(?Team $team, string $feld, mixed $code): bool
    {
        return is_string($code) && $code !== '' && isset($this->optionen($team, $feld)[$code]);
    }

    public function label(?Team $team, string $feld, ?string $code): ?string
    {
        return ($code === null || $code === '') ? null : ($this->optionen($team, $feld)[$code] ?? $code);
    }

    /**
     * Alt-Werte umsetzen: `occasion = konferenz` wird zu `eventtyp = konferenz`, Anlass leer (nur, wenn kein Eventtyp gesetzt ist).
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function normalisieren(array $p): array
    {
        foreach (self::ALIASE as $feld => $aliase) {
            $wert = $p[$feld] ?? null;
            if (is_string($wert) && isset($aliase[$wert])) {
                $ziel = $aliase[$wert];
                if (($p[$ziel['feld']] ?? '') === '' || ($p[$ziel['feld']] ?? null) === null) {
                    $p[$ziel['feld']] = $ziel['code'];
                }
                $p[$feld] = '';
            }
        }

        return $p;
    }
}
