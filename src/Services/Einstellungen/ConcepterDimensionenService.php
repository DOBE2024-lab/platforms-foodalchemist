<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Support\BasisKatalog;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 98 · 2.3a — Concepter-Facetten (Einstellungen › Concepter-Dimensionen) headless anlegen, (de)aktivieren, löschen.
 * Regeln: Name je Vokabular in der eigenen Sicht eindeutig (F2), nur das Besitzer-Team schreibt (Servierformen als
 * Basis-Katalog: Master pflegt global), WaWi-Import-Servierformen nie löschen, genutzte Einträge nur deaktivieren (V-06).
 *
 * Fehler: GeerbtNurLesenException / NochVerwendetException (RuntimeException) und \InvalidArgumentException — die
 * Meldungen sind für Menschen geschrieben und werden so angezeigt.
 */
class ConcepterDimensionenService
{
    /** Whitelist: key => [tabelle, label, hint] */
    public const VOKABULARE = [
        'einsatzmomente' => ['tabelle' => 'foodalchemist_service_moments', 'label' => 'Einsatzmomente', 'hint' => 'Mehrere je Konzept möglich, zum Beispiel Frühstück, Lunch, Apéro.'],
        'eventtypen' => ['tabelle' => 'foodalchemist_event_types', 'label' => 'Eventtypen', 'hint' => 'Einer je Konzept, zum Beispiel Konferenz, Gala, Sommerfest.'],
        'saisons' => ['tabelle' => 'foodalchemist_seasons', 'label' => 'Saisons', 'hint' => 'Mehrere je Konzept möglich.'],
        'servierformen' => ['tabelle' => 'foodalchemist_serving_forms', 'label' => 'Servierformen', 'hint' => 'Verbindet Konzept und Darreichung am Gericht. Der Grundbestand kommt aus der Warenwirtschaft, eigene Einträge lassen sich ergänzen.'],
        // CQ 2.5b: Service-Art der Planung (Tellerservice, Buffet, Flying …), getrennt von den Darreichungs-Servierformen; Grundbestand global.
        'servicearten' => ['tabelle' => 'foodalchemist_service_arten', 'label' => 'Service-Arten', 'hint' => 'Wie serviert wird, Vorgabe in der Planung, zum Beispiel Tellerservice, Buffet, Flying Service.'],
        // Spec 19 „Foodbook-Leitstelle" (E3.3): Zielgruppen-Vokabular (FA-nativ). Default am Foodbook,
        // Auswahl pro Kapitel, Stempel aufs Konzept beim Kapitel-Go (Pivots aus M1).
        'zielgruppen' => ['tabelle' => 'foodalchemist_target_groups', 'label' => 'Zielgruppen', 'hint' => 'Vorgabe am Foodbook, Auswahl je Kapitel, zum Beispiel Tagungsgast, Bankettgast, VIP-Gala.'],
        // Spec 19 „Foodbook-Leitstelle" (E3.6): Outlet-Vokabular (FA-nativ). NUR optionaler Kapitel-Tag
        // (Ausgabestelle) — KEINE Planungs-Ebene, NICHT in leitplanken(). Keine Seeds — rein team-definiert.
        'outlets' => ['tabelle' => 'foodalchemist_outlets', 'label' => 'Ausgabestellen', 'hint' => 'Freiwillige Markierung am Kapitel, zum Beispiel Restaurant, Bankett, Bar. Keine eigene Planungsebene.'],
    ];

    /** Legt einen Eintrag an und gibt den (getrimmten) Namen zurück. */
    public function anlegen(Team $team, string $vokabular, string $name): string
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        $name = trim($name);
        if ($meta === null || $name === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }
        $nameSpalte = $vokabular === 'servierformen' ? 'label' : 'name';
        // F2 (unique je Team): Kollision nur in der eigenen Sicht — ein ungescopter Check blockierte Mandanten, sobald IRGENDEIN Team den
        // Namen hat, und verriet dessen Existenz (wie Behaelter::create, E2)
        if (TeamScope::applyVisible(DB::table($meta['tabelle'])->where($nameSpalte, $name)->whereNull('deleted_at'), 'team_id', $team)->exists()) {
            throw new \InvalidArgumentException("«{$name}» existiert schon in {$meta['label']}.");
        }

        $zeile = [
            'uuid' => (string) Str::uuid7(),
            'team_id' => $team->id,
            $nameSpalte => $name,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ];
        if ($vokabular === 'servierformen') {
            $zeile['code'] = Str::slug($name, '_'); // FA-nativ, ohne legacy_id — Import fasst sie nicht an
        } elseif (in_array($meta['tabelle'], \Platform\FoodAlchemist\Support\Planung\VokabularCodes::TABELLEN, true)) {
            $zeile['code'] = \Platform\FoodAlchemist\Support\Planung\VokabularCodes::codeFuer($meta['tabelle'], $name);   // CQ 2.5b: Planung speichert den Code
        }
        DB::table($meta['tabelle'])->insert($zeile);

        return $name;
    }

    /** V-06: nur deaktivieren — inaktive bleiben an bestehenden Concepts sichtbar. false = Vokabular/Eintrag unbekannt (still). */
    public function aktivUmschalten(Team $team, string $vokabular, int $id): bool
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        $zeile = $meta !== null ? DB::table($meta['tabelle'])->where('id', $id)->first(['is_inactive', 'team_id']) : null;
        if ($zeile === null) {
            return false;
        }
        if (! $this->darf($vokabular, $zeile->team_id, $team)) {
            throw new GeerbtNurLesenException($meta['label'], 'Geerbter/Master-Eintrag — nur das Besitzer-Team kann ändern.');
        }
        DB::table($meta['tabelle'])->where('id', $id)
            ->update(['is_inactive' => ! $zeile->is_inactive, 'updated_at' => now()]);

        return true;
    }

    /**
     * Hart löschen nur wenn ungenutzt; Servierform-Import-Einträge (legacy_id) nie löschen.
     *
     * @return string|null Name des gelöschten Eintrags; null = Vokabular/Eintrag unbekannt (still)
     */
    public function loeschen(Team $team, string $vokabular, int $id): ?string
    {
        $meta = self::VOKABULARE[$vokabular] ?? null;
        $zeile = $meta !== null ? DB::table($meta['tabelle'])->where('id', $id)->first() : null;
        if ($zeile === null) {
            return null;
        }
        $name = $zeile->label ?? $zeile->name;
        if (! $this->darf($vokabular, $zeile->team_id, $team)) {
            throw new GeerbtNurLesenException($meta['label'], "«{$name}» ist geerbt/Master — nur das Besitzer-Team kann löschen.");
        }
        if ($vokabular === 'servierformen' && $zeile->legacy_id !== null) {
            throw new \InvalidArgumentException("«{$name}» kommt aus der WaWi (Master) — nur deaktivieren.");
        }
        $n = $this->referenzen($vokabular, $id);
        if ($n > 0) {
            throw new NochVerwendetException("«{$name}» wird {$n}× genutzt — erst umhängen oder deaktivieren.");
        }
        DB::table($meta['tabelle'])->where('id', $id)->delete();

        return $name;
    }

    /** Servierformen und Service-Arten sind Basis-Katalog (Master pflegt global); die übrigen Vokabulare bleiben Team-Sache (Paket/Team). */
    private function darf(string $vokabular, mixed $zeileTeamId, Team $team): bool
    {
        return in_array($vokabular, ['servierformen', 'servicearten'], true)
            ? BasisKatalog::mayWrite($zeileTeamId, $team)
            : TeamScope::owns($zeileTeamId, $team);
    }

    private function referenzen(string $vokabular, int $id): int
    {
        return match ($vokabular) {
            'einsatzmomente' => DB::table('foodalchemist_concept_service_moments')->where('service_moment_id', $id)->count(),
            'eventtypen' => DB::table('foodalchemist_concepts')->where('event_type_id', $id)->whereNull('deleted_at')->count(),
            'saisons' => DB::table('foodalchemist_concept_seasons')->where('season_id', $id)->count(),
            'servierformen' => DB::table('foodalchemist_concepts')->where('serving_form_id', $id)->whereNull('deleted_at')->count()
                + DB::table('foodalchemist_recipe_presentations')->where('serving_form_id', $id)->whereNull('deleted_at')->count(),
            // Spec 19 (E3.3): Nutzung über alle 3 M1-Pivots (Foodbook-Default ∪ Kapitel-Auswahl ∪ Konzept-Stempel).
            'zielgruppen' => DB::table('foodalchemist_foodbook_target_groups')->where('target_group_id', $id)->count()
                + DB::table('foodalchemist_chapter_target_groups')->where('target_group_id', $id)->count()
                + DB::table('foodalchemist_concept_target_groups')->where('target_group_id', $id)->count(),
            // Spec 19 (E3.6): Outlet-Tag am Kapitel (lose Spalte, keine Pivot/FK).
            'outlets' => DB::table('foodalchemist_foodbook_chapters')->where('outlet_id', $id)->whereNull('deleted_at')->count(),
            default => 0,
        };
    }
}
