<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;

/**
 * 98 · 2.3a — Lagerorte (Einstellungen › Einkauf) headless anlegen, ändern, Standard setzen, entfernen.
 * Team-strikt wie Bestand und Inventur: nur Lagerorte des eigenen Teams (fremde → ModelNotFoundException, wie bisher 404).
 * Der erste Lagerort wird Standard; ein Lagerort mit Bestand wird beim Entfernen nur deaktiviert; fällt der Standard weg,
 * übernimmt der erste aktive Lagerort (nach Name).
 *
 * Fehler: \InvalidArgumentException für Eingaben — die Meldung wird so angezeigt.
 */
class LagerorteService
{
    /** Erlaubte Lagertypen → Anzeige-Label; Unbekanntes fällt auf 'warehouse'. */
    public const TYPEN = [
        'warehouse' => 'Lager',
        'cooling' => 'Kühlhaus',
        'freezer' => 'TK-Lager',
        'dry' => 'Trockenlager',
        'production' => 'Produktion',
    ];

    /** @param array{name?:string,code?:string,type?:string,note?:string} $eingabe */
    public function anlegen(Team $team, array $eingabe): FoodAlchemistInventoryLocation
    {
        $name = $this->name($eingabe);
        $hatLager = FoodAlchemistInventoryLocation::where('team_id', $team->id)->exists();

        return FoodAlchemistInventoryLocation::create([
            'team_id' => $team->id,
            'name' => $name,
            'code' => trim((string) ($eingabe['code'] ?? '')) ?: null,
            'type' => self::typ((string) ($eingabe['type'] ?? 'warehouse')),
            'is_default' => ! $hatLager,
            'is_active' => true,
            'note' => trim((string) ($eingabe['note'] ?? '')) ?: null,
        ]);
    }

    /** @param array{name?:string,code?:string,type?:string,note?:string,is_active?:bool} $eingabe */
    public function speichern(Team $team, int $id, array $eingabe): FoodAlchemistInventoryLocation
    {
        $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail($id);
        $name = $this->name($eingabe);
        $location->update([
            'name' => $name,
            'code' => trim((string) ($eingabe['code'] ?? '')) ?: null,
            'type' => self::typ((string) ($eingabe['type'] ?? 'warehouse')),
            'is_active' => (bool) ($eingabe['is_active'] ?? false),
            'note' => trim((string) ($eingabe['note'] ?? '')) ?: null,
        ]);

        return $location;
    }

    /** Genau ein Standardlager je Team; der Standard ist immer aktiv. */
    public function standardSetzen(Team $team, int $id): FoodAlchemistInventoryLocation
    {
        return DB::transaction(function () use ($team, $id) {
            $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->lockForUpdate()->findOrFail($id);
            FoodAlchemistInventoryLocation::where('team_id', $team->id)->update(['is_default' => false]);
            $location->is_default = true;
            $location->is_active = true;
            $location->save();

            return $location;
        });
    }

    /**
     * Mit Bestand nur deaktivieren, sonst löschen. War es der Standard, wird der erste aktive Lagerort (nach Name) Standard.
     *
     * @return array{deaktiviert: bool, neuerStandardId: ?int}
     */
    public function entfernen(Team $team, int $id): array
    {
        $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->withCount('stocks')->findOrFail($id);
        $deaktiviert = $location->stocks()->where('qty_base', '!=', 0)->exists();
        if ($deaktiviert) {
            $location->is_active = false;
            $location->save();
        } else {
            $location->delete();
        }

        $neuerStandardId = null;
        if ((bool) $location->is_default) {
            $next = FoodAlchemistInventoryLocation::where('team_id', $team->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->first();
            if ($next !== null) {
                $neuerStandardId = (int) $this->standardSetzen($team, (int) $next->id)->id;
            }
        }

        return ['deaktiviert' => $deaktiviert, 'neuerStandardId' => $neuerStandardId];
    }

    public static function typ(string $type): string
    {
        return array_key_exists($type, self::TYPEN) ? $type : 'warehouse';
    }

    private function name(array $eingabe): string
    {
        $name = trim((string) ($eingabe['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Bitte einen Lagernamen eingeben.');
        }

        return $name;
    }
}
