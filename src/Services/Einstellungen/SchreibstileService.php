<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 98 · 2.3a — Schreibstile (Einstellungen › Schreibstile) headless anlegen, ändern, (de)aktivieren, löschen.
 * Regeln: Name und Sprachstil Pflicht, Slug eindeutig, nur das Besitzer-Team schreibt, Löschen nur ohne Verwendung
 * in Konzepten/Foodbooks.
 *
 * Fehler: GeerbtNurLesenException / NochVerwendetException (RuntimeException) und \InvalidArgumentException — die
 * Meldungen sind für Menschen geschrieben und werden so angezeigt.
 */
class SchreibstileService
{
    private const TABELLE = 'foodalchemist_writing_styles';

    private const FREMD_AENDERN = 'Dieser Schreibstil gehört einem anderen Team. Ändern kann ihn nur das Team, das ihn angelegt hat.';

    public function aktualisieren(Team $team, int $id, array $eingabe): void
    {
        $this->pflichtfelder($eingabe);
        $besitz = DB::table(self::TABELLE)->where('id', $id)->first(['team_id']);
        if ($besitz === null || ! TeamScope::owns($besitz->team_id, $team)) {
            throw new GeerbtNurLesenException('Schreibstil', self::FREMD_AENDERN);
        }
        DB::table(self::TABELLE)->where('id', $id)->update([
            'name' => trim($eingabe['name']),
            'sprach_duktus' => trim($eingabe['sprach_duktus']),
            'beispiele_md' => trim((string) ($eingabe['beispiele_md'] ?? '')) ?: null,
            'description' => ($eingabe['description'] ?? null) ?: null,
            'sort_order' => (int) ($eingabe['sort_order'] ?? 0),
            'updated_at' => now(),
        ]);
    }

    /** @return int ID des neuen Schreibstils */
    public function anlegen(Team $team, array $eingabe): int
    {
        $this->pflichtfelder($eingabe);
        $name = trim($eingabe['name']);
        $slug = Str::slug($name, '_');
        if (DB::table(self::TABELLE)->where('slug', $slug)->whereNull('deleted_at')->exists()) {
            throw new \InvalidArgumentException("Der Schreibstil «{$name}» existiert schon.");
        }

        return (int) DB::table(self::TABELLE)->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'slug' => $slug,
            'name' => $name,
            'sprach_duktus' => trim($eingabe['sprach_duktus']),
            'beispiele_md' => trim((string) ($eingabe['beispiele_md'] ?? '')) ?: null,
            'description' => ($eingabe['description'] ?? null) ?: null,
            'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** false = Schreibstil gibt es nicht (still). */
    public function aktivUmschalten(Team $team, int $id): bool
    {
        $zeile = DB::table(self::TABELLE)->where('id', $id)->first(['is_inactive', 'team_id']);
        if ($zeile === null) {
            return false;
        }
        if (! TeamScope::owns($zeile->team_id, $team)) {
            throw new GeerbtNurLesenException('Schreibstil', self::FREMD_AENDERN);
        }
        DB::table(self::TABELLE)->where('id', $id)
            ->update(['is_inactive' => ! $zeile->is_inactive, 'updated_at' => now()]);

        return true;
    }

    /** Phase 5: hart löschen, wenn nicht von Concepts/Foodbooks referenziert (sonst locked). false = gibt es nicht (still). */
    public function loeschen(Team $team, int $id): bool
    {
        $zeile = DB::table(self::TABELLE)->where('id', $id)->first(['team_id', 'name']);
        if ($zeile === null) {
            return false;
        }
        if (! TeamScope::owns($zeile->team_id, $team)) {
            throw new GeerbtNurLesenException('Schreibstil', 'Dieser Schreibstil gehört einem anderen Team. Löschen kann ihn nur das Team, das ihn angelegt hat.');
        }
        $nConcept = Schema::hasTable('foodalchemist_concepts')
            ? DB::table('foodalchemist_concepts')->whereNull('deleted_at')->where('writing_style_id', $id)->count() : 0;
        $nFoodbook = Schema::hasTable('foodalchemist_foodbooks')
            ? DB::table('foodalchemist_foodbooks')->whereNull('deleted_at')->where('writing_style_id', $id)->count() : 0;
        if ($nConcept + $nFoodbook > 0) {
            throw new NochVerwendetException("Wird noch verwendet ({$nConcept} Konzepte, {$nFoodbook} Foodbooks). Erst dort einen anderen Stil wählen oder diesen deaktivieren.");
        }
        DB::table(self::TABELLE)->where('id', $id)->delete();

        return true;
    }

    private function pflichtfelder(array $eingabe): void
    {
        if (trim((string) ($eingabe['name'] ?? '')) === '' || trim((string) ($eingabe['sprach_duktus'] ?? '')) === '') {
            throw new \InvalidArgumentException('Name und Sprachstil sind Pflicht.');
        }
    }
}
