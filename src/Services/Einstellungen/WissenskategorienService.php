<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService;
use Platform\FoodAlchemist\Services\Knowledge\WissensSichtbarkeit;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 98 · 2.3a — Wissens-Kategorien (Einstellungen › Wissenskategorien, #469) headless anlegen, umbenennen,
 * (de)aktivieren, löschen. Schreibrecht: eigene Zeile immer, global (team_id NULL) nur als Master-Team, fremde nie
 * ({@see WissensSichtbarkeit::mayWrite()}). Lesen bleibt gemeinsam — gescopet ist nur das Schreiben.
 *
 * Bewusst nicht im KnowledgeService: dessen createCategory/setActive sind der MCP-Weg mit eigener Slug-/Meldungslogik;
 * diese Seite behält ihr bisheriges Verhalten (Löschen samt Docs, Sortierung hinten an).
 *
 * Fehler: GeerbtNurLesenException / NochVerwendetException (RuntimeException) und \InvalidArgumentException — die
 * Meldungen sind für Menschen geschrieben und werden so angezeigt.
 */
class WissenskategorienService
{
    private const TABELLE = 'foodalchemist_knowledge_categories';

    public function aktualisieren(Team $team, int $id, array $eingabe): void
    {
        if (trim((string) ($eingabe['label'] ?? '')) === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }
        $kat = DB::table(self::TABELLE)->where('id', $id)->first(['team_id']);
        if ($kat === null || ! WissensSichtbarkeit::mayWrite($kat->team_id, $team)) {
            throw new GeerbtNurLesenException('Kategorie', 'Geerbtes/globales Vokabular — umbenennen kann nur das Besitzer-Team.');
        }
        DB::table(self::TABELLE)->where('id', $id)->update([
            'label' => trim($eingabe['label']),
            'description' => ($eingabe['description'] ?? '') !== '' ? trim((string) $eingabe['description']) : null,
            'sort_order' => (int) ($eingabe['sort_order'] ?? 0),
            'updated_at' => now(),
        ]);
    }

    /** @return string Slug der neuen Kategorie */
    public function anlegen(Team $team, array $eingabe): string
    {
        $label = trim((string) ($eingabe['label'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('Name ist Pflicht.');
        }
        $slug = Str::slug($label, '_');
        $exists = DB::table(self::TABELLE)
            ->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
            ->whereNull('deleted_at')->exists();
        if ($exists) {
            throw new \InvalidArgumentException("Kategorie «{$slug}» existiert schon.");
        }
        $maxSort = (int) DB::table(self::TABELLE)->max('sort_order');
        DB::table(self::TABELLE)->insert([
            'uuid' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'slug' => $slug,
            'label' => $label,
            'description' => ($eingabe['description'] ?? '') !== '' ? trim((string) $eingabe['description']) : null,
            'sort_order' => $maxSort + 10,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $slug;
    }

    /** $id kommt vom Client — ohne Eigentumsprüfung liesse sich jede fremde/globale Kategorie abschalten (und ihr Wissen aus allen Prompts nehmen). */
    public function aktivUmschalten(Team $team, int $id): void
    {
        $zeile = DB::table(self::TABELLE)->where('id', $id)->first(['active', 'team_id']);
        if ($zeile === null || ! WissensSichtbarkeit::mayWrite($zeile->team_id, $team)) {
            throw new GeerbtNurLesenException('Kategorie', 'Geerbtes/globales Vokabular — aktiv/inaktiv setzt nur das Besitzer-Team.');
        }
        DB::table(self::TABELLE)->where('id', $id)
            ->update(['active' => ! $zeile->active, 'updated_at' => now()]);
    }

    /**
     * Kategorie SAMT aller darin liegenden Docs endgültig löschen (HARD, kein Undo) — echtes Aufräumen.
     *
     * Reichweite (Mandanten-sicher):
     *  - Eigene Docs (team_id = aktives Team) werden immer gelöscht.
     *  - Global geseedete Docs (team_id NULL, u.a. die Pairing-Docs) NUR als Master-Team — sonst könnte ein Kind-Team den
     *    geteilten BHG-Korpus für alle wegräumen.
     *  - Docs FREMDER Teams werden nie angefasst. Bleiben dadurch welche an der Kategorie hängen, wird die Kategorie-Zeile
     *    bewusst NICHT entfernt: NochVerwendetException — die löschbaren Docs sind dann aber BEREITS gelöscht (wie bisher).
     *
     * Was mitgeht: Aliase/Bindungen cascaden per FK am Doc; der Semantik-Index (core_embeddings, kein FK) wird pro Doc
     * explizit über die Core-API entfernt (best-effort).
     *
     * @return array{label: string, docs: int}|null null = Kategorie gibt es nicht (still)
     */
    public function loeschen(Team $team, int $id): ?array
    {
        $istMaster = WissensSichtbarkeit::darfGlobalSchreiben($team);   // §13 H: nur das Master-Team, nicht jedes elternlose Team

        $kat = DB::table(self::TABELLE)->where('id', $id)->first(['id', 'slug', 'label', 'team_id']);
        if ($kat === null) {
            return null;
        }

        // Kategorie-Zeile: eigene ODER (global nur als Master). Geerbte/fremde bleiben tabu.
        if (! (TeamScope::owns($kat->team_id, $team) || ($kat->team_id === null && $istMaster))) {
            throw new GeerbtNurLesenException('Kategorie', "«{$kat->slug}» ist geerbtes/globales Vokabular — nur das Master-Team kann es löschen.");
        }

        // Löschbare Docs bestimmen (eigene immer; global nur als Master; fremde nie).
        $docs = DB::table('foodalchemist_knowledge_documents')
            ->where('category', $kat->slug)->whereNull('deleted_at')
            ->where(function ($q) use ($team, $istMaster) {
                $q->where('team_id', $team->id);
                if ($istMaster) {
                    $q->orWhereNull('team_id');
                }
            })
            ->get(['id', 'team_id']);

        if ($docs->isNotEmpty()) {
            $this->purgeEmbeddings($docs);          // best-effort, wirft nie → DB-Delete läuft immer
            DB::table('foodalchemist_knowledge_documents')->whereIn('id', $docs->pluck('id')->all())->delete();
        }

        // Hängen noch Docs fremder Teams an der Kategorie? Dann Kategorie NICHT entfernen.
        $rest = DB::table('foodalchemist_knowledge_documents')
            ->where('category', $kat->slug)->whereNull('deleted_at')->count();
        if ($rest > 0) {
            throw new NochVerwendetException("{$docs->count()} Dok(s) gelöscht; {$rest} Dok(s) anderer Teams bleiben — Kategorie «{$kat->slug}» nicht entfernt.");
        }

        DB::table(self::TABELLE)->where('id', $id)->delete();

        return ['label' => (string) $kat->label, 'docs' => $docs->count()];
    }

    /**
     * Semantik-Vektoren der gelöschten Docs aus dem Core-Store nehmen (kein FK am Doc). Rein best-effort: ein
     * fehlender/kaputter Provider oder Store darf den eigentlichen Löschvorgang nicht kippen — deshalb schluckt der
     * Helfer alles. Ein verwaister Vektor löst ohne Doc ohnehin nie auf.
     *
     * @param  Collection<int, object>  $docs  Zeilen mit id + team_id
     */
    private function purgeEmbeddings(Collection $docs): void
    {
        try {
            $emb = app(\Platform\Core\Services\EmbeddingService::class);
            $globalTeam = (int) config('foodalchemist.semantic_search.global_team_id', 0);
            foreach ($docs as $d) {
                try {
                    $emb->delete($d->team_id === null ? $globalTeam : (int) $d->team_id, KnowledgeEmbeddingService::ENTITY_TYPE, $d->id);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
    }
}
