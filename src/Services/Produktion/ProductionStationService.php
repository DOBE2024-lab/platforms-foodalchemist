<?php

namespace Platform\FoodAlchemist\Services\Produktion;

use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;

/**
 * 98 · 1.3c · Schreibwege an Produktions-Posten (Stammdaten), damit Tools nicht selbst speichern.
 * 98 · 2.3a · Einstellungen › Posten schreibt ebenfalls nur hierüber: anlegen, Felder, Wochentag-Abweichung, Besetzung,
 * stilllegen. Nur Posten des EIGENEN Teams sind editierbar (D1) — geerbte sind Vorlagen; gelöscht wird nie (V-06).
 *
 * Fehler: GeerbtNurLesenException (RuntimeException) und \InvalidArgumentException — die Meldungen werden so angezeigt.
 */
class ProductionStationService
{
    /**
     * Spec 83 B3g: Pass = Sammelposten. Nur das Besitzer-Team pflegt den Posten.
     *
     * @throws \RuntimeException bei geerbtem Posten
     */
    public function setzeSammelposten(Team $team, FoodAlchemistProductionStation $posten, bool $sammel): FoodAlchemistProductionStation
    {
        if (! $posten->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Posten ist eine Vorlage — Pflege nur durchs Besitzer-Team.');
        }
        $posten->ist_sammelposten = $sammel;
        $posten->save();

        return $posten;
    }

    /** Nur Posten des EIGENEN Teams (D1) — sonst wirft es mit der Meldung, die die Oberfläche zeigt. */
    public function eigener(Team $team, int $id): FoodAlchemistProductionStation
    {
        $posten = FoodAlchemistProductionStation::where('team_id', $team->id)->find($id);
        if ($posten === null) {
            throw new GeerbtNurLesenException('Posten', 'Posten nicht im Schreibzugriff (D1).');
        }

        return $posten;
    }

    /** @param array{name?: string, group_name?: string, kapazitaet?: string} $eingabe */
    public function anlegen(Team $team, array $eingabe): FoodAlchemistProductionStation
    {
        $name = trim((string) ($eingabe['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }
        $slug = Str::slug($name, '_');
        if (FoodAlchemistProductionStation::where('team_id', $team->id)->where('slug', $slug)->exists()) {
            throw new \InvalidArgumentException("«{$name}» gibt es schon.");
        }

        return FoodAlchemistProductionStation::create([
            'team_id' => $team->id,
            'slug' => $slug,
            'name' => $name,
            'group_name' => trim((string) ($eingabe['group_name'] ?? '')) ?: null,
            'kapazitaet_min_pro_tag' => self::minuten((string) ($eingabe['kapazitaet'] ?? '')),
            'sort_order' => (int) FoodAlchemistProductionStation::where('team_id', $team->id)->max('sort_order') + 10,
        ]);
    }

    /** Inline-Edit eines Feldes; unbekannte Felder ändern nichts. */
    public function feldSetzen(Team $team, int $id, string $feld, string $wert): FoodAlchemistProductionStation
    {
        $posten = $this->eigener($team, $id);
        if ($feld === 'name' && trim($wert) === '') {
            throw new \InvalidArgumentException('Name darf nicht leer sein.');
        }
        match ($feld) {
            'name' => $posten->name = trim($wert),
            'group_name' => $posten->group_name = trim($wert) ?: null,
            'kapazitaet' => $posten->kapazitaet_min_pro_tag = self::minuten($wert),
            'schicht' => $posten->schicht_minuten = self::minuten($wert),                 // Stufe 3
            'batch_max_kg' => $posten->batch_max_kg = self::zahl($wert),                    // Stufe 3 (Topf)
            'ist_sammelposten' => $posten->ist_sammelposten = in_array($wert, ['1', 'true', 'on'], true),   // Spec 83 B3g
            default => null,
        };
        $posten->save();

        return $posten;
    }

    /** Wochentag-Abweichung (ISO 1=Mo…7=So); leerer Wert löscht sie — der Posten fällt auf die Normalkapazität zurück. */
    public function wochentagSetzen(Team $team, int $id, int $iso, string $wert): FoodAlchemistProductionStation
    {
        $posten = $this->eigener($team, $id);
        $tage = $posten->kapazitaet_wochentag ?? [];
        $minuten = self::minuten($wert);
        if ($minuten === null && trim($wert) === '') {
            unset($tage[(string) $iso]);
        } else {
            $tage[(string) $iso] = $minuten ?? 0;
        }
        $posten->kapazitaet_wochentag = $tage === [] ? null : $tage;
        $posten->save();

        return $posten;
    }

    /** Stufe 3 — Anzahl einer Rolle am Posten (0/leer = Rolle raus). Leitet Kapazität + Kosten ab. */
    public function besetzungSetzen(Team $team, int $id, int $roleId, string $wert): FoodAlchemistProductionStation
    {
        $posten = $this->eigener($team, $id);
        $besetzung = $posten->besetzung ?? [];
        $anzahl = max(0, (int) trim($wert));
        if ($anzahl === 0) {
            unset($besetzung[(string) $roleId]);
        } else {
            $besetzung[(string) $roleId] = $anzahl;
        }
        $posten->besetzung = $besetzung === [] ? null : $besetzung;
        $posten->save();

        return $posten;
    }

    /** Lösch-Schutz V-06: nur stilllegen / wieder aktivieren. */
    public function aktivUmschalten(Team $team, int $id): FoodAlchemistProductionStation
    {
        $posten = $this->eigener($team, $id);
        $posten->is_inactive = ! $posten->is_inactive;
        $posten->save();

        return $posten;
    }

    /** Minuten-Eingabe robust lesen: leer ⇒ null (= kein Kapazitätsposten), sonst ≥ 0. */
    public static function minuten(string $roh): ?int
    {
        $roh = trim($roh);

        return $roh === '' ? null : max(0, (int) $roh);
    }

    /** Dezimal-Eingabe (Topf-Deckel): leer ⇒ null, Komma erlaubt, ≥ 0. */
    public static function zahl(string $roh): ?float
    {
        $roh = trim(str_replace(',', '.', $roh));

        return $roh === '' ? null : max(0.0, (float) $roh);
    }
}
