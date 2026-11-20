<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;

/**
 * 98 · 2.3a — Küchen-Rollen (Einstellungen › Rollen, Stufe 3 P3.1) headless anlegen, ändern, stilllegen.
 * Team-eigene Betriebsstammdaten: nur Rollen des EIGENEN Teams sind editierbar (geerbte sind Vorlagen), Slug je Team
 * eindeutig, gelöscht wird nie (nur stilllegen). Betriebs-Overrides der Sätze laufen über den OutletSettingsService.
 *
 * Fehler: GeerbtNurLesenException (RuntimeException) und \InvalidArgumentException — die Meldungen werden so angezeigt.
 */
class KuechenRollenService
{
    /** Nur Rollen des EIGENEN Teams — sonst wirft es mit der Meldung, die die Oberfläche zeigt. */
    public function eigene(Team $team, int $id): FoodAlchemistKitchenRole
    {
        $rolle = FoodAlchemistKitchenRole::where('team_id', $team->id)->find($id);
        if ($rolle === null) {
            throw new GeerbtNurLesenException('Rolle', 'Rolle nicht im Schreibzugriff.');
        }

        return $rolle;
    }

    /** @param array{name?: string, satz?: string} $eingabe */
    public function anlegen(Team $team, array $eingabe): FoodAlchemistKitchenRole
    {
        $name = trim((string) ($eingabe['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }
        $slug = Str::slug($name, '_');
        if (FoodAlchemistKitchenRole::where('team_id', $team->id)->where('slug', $slug)->exists()) {
            throw new \InvalidArgumentException("«{$name}» gibt es schon.");
        }

        return FoodAlchemistKitchenRole::create([
            'team_id' => $team->id,
            'slug' => $slug,
            'name' => $name,
            'stundensatz_eur' => self::satz((string) ($eingabe['satz'] ?? '')),
            'sort_order' => (int) FoodAlchemistKitchenRole::where('team_id', $team->id)->max('sort_order') + 10,
        ]);
    }

    /** Inline-Edit (Name oder €/Std-Satz); unbekannte Felder ändern nichts. */
    public function feldSetzen(Team $team, int $id, string $feld, string $wert): FoodAlchemistKitchenRole
    {
        $rolle = $this->eigene($team, $id);
        if ($feld === 'name' && trim($wert) === '') {
            throw new \InvalidArgumentException('Name darf nicht leer sein.');
        }
        match ($feld) {
            'name' => $rolle->name = trim($wert),
            'satz' => $rolle->stundensatz_eur = self::satz($wert),
            default => null,
        };
        $rolle->save();

        return $rolle;
    }

    /** Lösch-Schutz: nur stilllegen / wieder aktivieren. */
    public function aktivUmschalten(Team $team, int $id): FoodAlchemistKitchenRole
    {
        $rolle = $this->eigene($team, $id);
        $rolle->is_inactive = ! $rolle->is_inactive;
        $rolle->save();

        return $rolle;
    }

    /** €/Std robust lesen: leer ⇒ null (= flacher Team-Satz greift), sonst ≥ 0, Komma erlaubt. */
    public static function satz(string $roh): ?float
    {
        $roh = trim(str_replace(',', '.', $roh));

        return $roh === '' ? null : max(0.0, (float) $roh);
    }
}
