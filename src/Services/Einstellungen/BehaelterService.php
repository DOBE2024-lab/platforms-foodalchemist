<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Support\BasisKatalog;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 98 · 2.3a — Behälter & Geräte (Einstellungen › Behälter) headless anlegen, ändern, (de)aktivieren, löschen.
 * Die 3 Container-Vokabulare (D-6 §4.6) plus Koch-Equipment (D-5 §2.3). Schreiben darf das Besitzer-Team bzw.
 * der Master bei globalen Zeilen (BasisKatalog::mayWrite), Slug je sichtbarem Set eindeutig, Löschen nur unbenutzt.
 *
 * Fehler: NichtGefundenException / GeerbtNurLesenException / NochVerwendetException (RuntimeException) und
 * \InvalidArgumentException für Eingaben — die Meldungen sind für Menschen geschrieben und werden so angezeigt.
 */
class BehaelterService
{
    /** Whitelist: vokabular-key => [tabelle, label, hat kapazitaet_kg] */
    public const VOKABULARE = [
        'behaelter' => ['tabelle' => 'foodalchemist_vocab_containers', 'label' => 'Behälter (GN & Co.)', 'kapazitaet' => true],
        'regen' => ['tabelle' => 'foodalchemist_vocab_regeneration_devices', 'label' => 'Regenerations-Geräte', 'kapazitaet' => false],
        'vehikel' => ['tabelle' => 'foodalchemist_vocab_serving_vehicles', 'label' => 'Servier-Vehikel', 'kapazitaet' => false],
        'equipment' => ['tabelle' => 'foodalchemist_vocab_kitchen_equipment', 'label' => 'Koch-Equipment (Basisrezepte)', 'kapazitaet' => false],
    ];

    /**
     * Spec 51: Felder, die nur der Behälter-Katalog trägt. Fläche und Tiefe GETRENNT, weil eine
     * einzelne kg-Zahl »flach statt tief« nicht ausdrücken kann; `eignung` sagt, wofür ein Typ
     * freigegeben ist — GEPFLEGT, nicht aus einem Material abgeleitet.
     */
    public const BEHAELTER_FELDER = [
        'familie', 'format_code', 'laenge_mm', 'breite_mm', 'tiefe_mm', 'volumen_l',
        'nutzfaktor', 'max_fuellgewicht_kg', 'ist_traeger', 'traeger_plaetze', 'traeger_format',
    ];

    /** Diese Felder kommen als Komma-Zahl aus dem Formular. */
    public const ZAHLEN = ['laenge_mm', 'breite_mm', 'tiefe_mm', 'volumen_l', 'nutzfaktor', 'max_fuellgewicht_kg', 'kapazitaet_kg'];

    private const FREMD_AENDERN = 'Fremder Eintrag — nur das Besitzer-Team (bzw. der Master bei globalen Zeilen) kann ändern.';

    /** Behälter-Zeile, die das Team pflegen darf (Bearbeiten-Formular). */
    public function eigenerBehaelter(Team $team, int $id): object
    {
        $zeile = DB::table('foodalchemist_vocab_containers')->where('id', $id)->first();
        if ($zeile === null) {
            throw new NichtGefundenException('Diesen Behälter gibt es nicht.');
        }
        // mayWrite statt owns: der Katalog-Grundstock liegt GLOBAL (team_id NULL) — sonst pflegte ihn niemand.
        if (! BasisKatalog::mayWrite($zeile->team_id, $team)) {
            throw new GeerbtNurLesenException('Behälter', self::FREMD_AENDERN);
        }

        return $zeile;
    }

    /** @return string der angelegte Name */
    public function anlegen(Team $team, string $vokabular, array $form): string
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        $name = trim($form['name'] ?? '');
        if ($meta === null || $name === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }
        $slug = Str::slug($name, '_');
        // E2: Die DB-Unique ist (team_id, slug). Ein ungescopter Check blockiert Kind-Teams,
        // sobald IRGENDEIN Team den Slug hat — und verrät mit der Meldung dessen Existenz.
        $kollision = TeamScope::applyVisible(
            DB::table($meta['tabelle'])->where('slug', $slug)->whereNull('deleted_at'), 'team_id', $team
        )->exists();
        if ($kollision) {
            throw new \InvalidArgumentException("«{$name}» existiert schon in {$meta['label']} ({$slug}).");
        }

        $zeile = [
            'uuid' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'slug' => $slug,
            'name' => $name,
            'group_name' => trim($form['group_name'] ?? '') ?: null,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($meta['kapazitaet']) {
            $zeile += $this->behaelterFelder($form);
        }
        DB::table($meta['tabelle'])->insert($zeile);

        return $name;
    }

    /** @return string der gespeicherte Name */
    public function aktualisieren(Team $team, int $id, array $form): string
    {
        $zeile = DB::table('foodalchemist_vocab_containers')->where('id', $id)->first(['team_id']);
        if ($zeile === null) {
            throw new NichtGefundenException(self::FREMD_AENDERN);
        }
        if (! BasisKatalog::mayWrite($zeile->team_id, $team)) {
            throw new GeerbtNurLesenException('Behälter', self::FREMD_AENDERN);
        }
        $name = trim((string) ($form['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }

        // Der Slug bleibt: Rezepte und Auswertungen hängen daran, ein Rename ist kein Neuanlegen.
        DB::table('foodalchemist_vocab_containers')->where('id', $id)->update(
            ['name' => $name, 'group_name' => trim((string) ($form['group_name'] ?? '')) ?: null, 'updated_at' => now()]
            + $this->behaelterFelder($form)
        );

        return $name;
    }

    /** V-06: nur deaktivieren — inaktive bleiben an Rezepten sichtbar. */
    public function aktivUmschalten(Team $team, string $vokabular, int $id): void
    {
        $meta = $this->meta($vokabular);
        $zeile = DB::table($meta['tabelle'])->where('id', $id)->first(['is_inactive', 'team_id']);
        if ($zeile === null) {
            throw new NichtGefundenException('Diesen Eintrag gibt es nicht.');
        }
        if (! BasisKatalog::mayWrite($zeile->team_id, $team)) {
            throw new GeerbtNurLesenException('Eintrag', 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann ändern.');
        }
        DB::table($meta['tabelle'])->where('id', $id)
            ->update(['is_inactive' => ! $zeile->is_inactive, 'updated_at' => now()]);
    }

    /** Phase 5: hart löschen, wenn von keinem Rezept genutzt (sonst locked → deaktivieren). @return string Name */
    public function loeschen(Team $team, string $vokabular, int $id): string
    {
        $meta = $this->meta($vokabular);
        $zeile = DB::table($meta['tabelle'])->where('id', $id)->first(['id', 'legacy_id', 'team_id', 'name']);
        if ($zeile === null) {
            throw new NichtGefundenException('Diesen Eintrag gibt es nicht.');
        }
        if (! BasisKatalog::mayWrite($zeile->team_id, $team)) {
            throw new GeerbtNurLesenException('Eintrag', 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann löschen.');
        }
        $n = $this->referenzen($vokabular, $zeile);
        if ($n > 0) {
            throw new NochVerwendetException("«{$zeile->name}» ist {$n}× in Verwendung (Rezepte, Darreichungen, Regeneration) — erst umhängen oder deaktivieren.");
        }
        DB::table($meta['tabelle'])->where('id', $id)->delete();

        return (string) $zeile->name;
    }

    private function meta(string $vokabular): array
    {
        return self::VOKABULARE[$vokabular] ?? throw new NichtGefundenException('Dieses Vokabular gibt es nicht.');
    }

    /** Komma-Eingaben zu Zahlen, Checkbox-Set zu JSON. Leeres Feld bleibt NULL, nicht 0. */
    private function behaelterFelder(array $form): array
    {
        $out = [];

        foreach (['kapazitaet_kg', ...self::BEHAELTER_FELDER] as $feld) {
            if ($feld === 'ist_traeger') {
                $out['ist_traeger'] = (bool) ($form['ist_traeger'] ?? false);

                continue;
            }
            $wert = trim((string) ($form[$feld] ?? ''));
            if ($wert === '') {
                $out[$feld] = null;

                continue;
            }
            if (in_array($feld, self::ZAHLEN, true)) {
                $zahl = str_replace(',', '.', $wert);
                $out[$feld] = is_numeric($zahl) ? (float) $zahl : null;

                continue;
            }
            if ($feld === 'traeger_plaetze') {
                $out[$feld] = ctype_digit($wert) ? (int) $wert : null;

                continue;
            }
            $out[$feld] = $wert;
        }

        // eignung === null heisst »nicht gepflegt« (keine bekannte Einschränkung), nicht »gesperrt«.
        $eignung = array_values(array_filter(
            (array) ($form['eignung'] ?? []),
            fn ($z) => in_array($z, FoodAlchemistVocabContainer::ZWECKE, true)
        ));
        $out['eignung'] = $eignung === [] ? null : json_encode($eignung);

        if ($out['nutzfaktor'] === null) {
            $out['nutzfaktor'] = 0.85;
        }

        return $out;
    }

    /**
     * Wo überall dieser Vokabel-Eintrag hängt — Grundlage des Lösch-Schutzes.
     *
     * BEFUND 2026-09-04 (Spec 51): die Vorgänger-Fassung zählte ausschliesslich die
     * `*_legacy_id`-Rohwerte des WaWi-Imports und stieg bei `legacy_id === null` sofort mit 0
     * aus. Damit war JEDE vom Kunden selbst angelegte Zeile hart löschbar, egal wie oft sie
     * genutzt wurde — und die echten Fremdschlüssel (`container_warm_vocab_id` &c.) sah sie
     * ohnehin nie, ebenso wenig Darreichungen und Regenerationszeilen. Weil alle Bestands-FKs
     * `nullOnDelete` sind, hätte ein Löschen den Behälter an Rezepten und Darreichungen STILL
     * entfernt.
     *
     * Die Bestands-FKs bleiben bewusst `nullOnDelete`: es kann Zeilen geben, die auf bereits
     * soft-gelöschte Vokabeln zeigen, und eine nachträgliche restrict-Migration würde daran
     * scheitern. Geschützt wird hier — nur die neue `recipe_containers` ist restrict.
     *
     * Defensiv über Schema-Prüfungen, damit die Zählung Schema-Drift überlebt statt zu werfen.
     */
    private function referenzen(string $vokabular, object $zeile): int
    {
        $treffer = 0;

        foreach ($this->nutzungsorte($vokabular) as [$tabelle, $spalten]) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            $vorhanden = array_values(array_filter($spalten, fn (string $c) => Schema::hasColumn($tabelle, $c)));
            if ($vorhanden === []) {
                continue;
            }

            $q = DB::table($tabelle);
            if (Schema::hasColumn($tabelle, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }

            $treffer += $q->where(function ($q) use ($vorhanden, $zeile) {
                foreach ($vorhanden as $c) {
                    $q->orWhere($c, $zeile->id);
                }
            })->count();
        }

        return $treffer + $this->legacyReferenzen($vokabular, $zeile);
    }

    /** Echte Fremdschlüssel je Vokabular: [tabelle, spalten]. */
    private function nutzungsorte(string $vokabular): array
    {
        return match ($vokabular) {
            'behaelter' => [
                ['foodalchemist_recipes', ['container_warm_vocab_id', 'container_cold_vocab_id']],
                ['foodalchemist_recipe_presentations', ['container_warm_vocab_id', 'container_cold_vocab_id']],
                ['foodalchemist_recipe_containers', ['container_vocab_id']],
            ],
            'regen' => [
                ['foodalchemist_recipe_regenerations', ['device_vocab_id']],
            ],
            'vehikel' => [
                ['foodalchemist_recipes', ['serving_vehicle_vocab_id']],
                ['foodalchemist_recipe_presentations', ['serving_vehicle_vocab_id']],
                ['foodalchemist_gps', ['serving_vehicle_vocab_id']],   // Typ des Gefäßes (Non-Food-GP)
            ],
            'equipment' => [
                ['foodalchemist_recipe_equipment', ['equipment_id']],
            ],
            default => [],
        };
    }

    /**
     * Zusätzlich die Import-Rohwerte: bei WaWi-Zeilen, deren `*_vocab_id` nie nachgezogen wurde,
     * ist die `legacy_id` der einzige Beleg für eine Nutzung.
     */
    private function legacyReferenzen(string $vokabular, object $zeile): int
    {
        if ($zeile->legacy_id === null || ! Schema::hasTable('foodalchemist_recipes')) {
            return 0;
        }

        $spalten = match ($vokabular) {
            'behaelter' => ['container_warm_legacy_id', 'container_cold_legacy_id'],
            'regen' => ['regeneration_device_legacy_id'],
            'vehikel' => ['serving_vehicle_legacy_id'],
            default => [],
        };
        $spalten = array_values(array_filter($spalten, fn (string $c) => Schema::hasColumn('foodalchemist_recipes', $c)));
        if ($spalten === []) {
            return 0;
        }

        return DB::table('foodalchemist_recipes')->whereNull('deleted_at')
            ->where(function ($q) use ($spalten, $zeile) {
                foreach ($spalten as $c) {
                    $q->orWhere($c, $zeile->legacy_id);
                }
            })->count();
    }
}
