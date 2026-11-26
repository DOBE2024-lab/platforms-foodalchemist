<?php

namespace Platform\FoodAlchemist\Services\Gericht;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchMethod;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Support\RezeptTypVokabular;

/**
 * Altbestand „GP direkt im Gericht" (Entscheid Dominique 17.11.2026: ein Gericht besteht nur aus Basisrezepten).
 *
 * Neue GP-Zeilen im Gericht sperrt {@see RecipeService::syncIngredients()}. Was vorher entstand, bleibt stehen, bis der
 * Mensch es umbaut — dieser Service ist der Umbau-Weg: er legt ein Basisrezept (Entwurf) mit genau dieser GP-Zutat und
 * Menge an und hängt die Gericht-Zeile darauf um (referenced_recipe_id statt gp_id). Kein automatischer Massen-Umbau.
 *
 * Name-Vorschlag (Regelwerk Basisrezepte §1.2/§17): ist aus der Warengruppe ein Typ ableitbar (Regel
 * basisrezept.zukauf.typ), heißt es „Typ: Bezeichnung (Zukauf)"; sonst nur ein Vorschlag mit Typ „Topping"
 * (`typ_sicher = false`) — der Name ist dann vom Menschen zu prüfen und frei editierbar.
 */
final class GerichtGpUmbauService
{
    /** Typ-Vorschlag, wenn aus der Warengruppe keiner ableitbar ist („Garnitur" ist im Typ-Vokabular ausdrücklich falsch). */
    public const TYP_FALLBACK = 'Topping';

    /**
     * Die Zeilen eines Gerichts, die ein GP direkt tragen (Altbestand). Basisrezepte: immer leer.
     *
     * @return Collection<int, FoodAlchemistRecipeIngredient>
     */
    public function direkteGpZeilen(FoodAlchemistRecipe $gericht): Collection
    {
        if (! $gericht->is_sales_recipe) {
            return collect();
        }

        return $gericht->ingredients()->whereNotNull('gp_id')->whereNull('referenced_recipe_id')
            ->with('gp:id,name,commodity_group_code,sub_category')->orderBy('position')->get();
    }

    /** @return array{name: string, typ: ?string, typ_sicher: bool, zukauf: bool} */
    public function nameVorschlag(FoodAlchemistRecipeIngredient $zeile): array
    {
        $gp = $zeile->gp;
        if ($gp === null) {
            $roh = trim((string) ($zeile->display_name ?: $zeile->raw_text));

            return ['name' => $roh, 'typ' => null, 'typ_sicher' => false, 'zukauf' => false];
        }
        $bezeichnung = \Platform\FoodAlchemist\Services\Rezept\ZukaufBasisrezeptService::bezeichnungFuerGp($gp) ?: (string) $gp->name;
        $typ = app(\Platform\FoodAlchemist\Services\Rezept\ZukaufBasisrezeptService::class)->typFuerGp($gp);
        if ($typ !== null) {
            return ['name' => \Platform\FoodAlchemist\Services\Rezept\ZukaufBasisrezeptService::name(['typ' => $typ, 'bezeichnung' => $bezeichnung]), 'typ' => $typ,
                'typ_sicher' => true, 'zukauf' => true];
        }
        $fallback = RezeptTypVokabular::finde(self::TYP_FALLBACK) ?? self::TYP_FALLBACK;

        return ['name' => \Platform\FoodAlchemist\Services\Rezept\RecipeKomponentenPlanService::bereinigeName($fallback . ': ' . $bezeichnung), 'typ' => $fallback,
            'typ_sicher' => false, 'zukauf' => false];
    }

    /**
     * Basisrezept (Entwurf) aus einer GP-Zeile des Gerichts anlegen und die Zeile darauf umhängen.
     * Gibt es schon ein team-eigenes Basisrezept mit genau diesem Namen, wird es verknüpft statt doppelt angelegt.
     *
     * @return array{recipe_id: int, name: string, neu: bool, gericht_id: int, ingredient_id: int}
     */
    public function basisrezeptAnlegen(Team $team, int $ingredientId, ?string $name = null): array
    {
        $zeile = FoodAlchemistRecipeIngredient::query()->with('gp')->find($ingredientId);
        $gericht = $zeile !== null ? FoodAlchemistRecipe::visibleToTeam($team)->find((int) $zeile->recipe_id) : null;
        if ($zeile === null || $gericht === null) {
            throw new \Platform\FoodAlchemist\Exceptions\NichtGefundenException('Zutaten-Zeile nicht gefunden.');
        }
        \Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException::wennNichtEigen($gericht, $team, 'das Gericht');
        if (! $gericht->is_sales_recipe) {
            throw new \RuntimeException('Die Zeile gehört zu einem Basisrezept — dort dürfen Grundprodukte stehen.');
        }
        if ($zeile->gp_id === null || $zeile->referenced_recipe_id !== null) {
            throw new \RuntimeException('Die Zeile trägt kein Grundprodukt direkt — nichts umzubauen.');
        }

        $vorschlag = $this->nameVorschlag($zeile);
        $name = trim((string) $name) ?: $vorschlag['name'];
        if ($name === '') {
            throw new \RuntimeException('Name des Basisrezepts ist Pflicht.');
        }

        $rezepte = app(\Platform\FoodAlchemist\Services\Rezept\RecipeService::class);
        $recompute = app(\Platform\FoodAlchemist\Services\Rezept\RecipeRecomputeService::class);

        return DB::transaction(function () use ($team, $zeile, $gericht, $name, $vorschlag, $rezepte, $recompute) {
            $bestehend = FoodAlchemistRecipe::query()->where('team_id', $team->id)->basis()
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();
            $neu = $bestehend === null;
            if ($neu) {
                $basis = $rezepte->create($team, [
                    'name' => $name,
                    'is_sales_recipe' => false,
                    'production_depth' => $vorschlag['zukauf'] && $name === $vorschlag['name'] ? 'convenience' : null,
                    'created_via' => 'editor',
                ] + $gericht->only(['vorgabe_convenience', 'vorgabe_niveau']));
                $rezepte->syncIngredients($team, (int) $basis->id, [[
                    'gp_id' => (int) $zeile->gp_id,
                    'raw_text' => (string) ($zeile->gp?->name ?? $zeile->raw_text),
                    'display_name' => (string) ($zeile->gp?->name ?? $zeile->display_name ?? ''),
                    'quantity' => (float) $zeile->quantity,
                    'quantity_max' => $zeile->quantity_max,
                    'unit_vocab_id' => (int) $zeile->unit_vocab_id,
                    'trimming_loss_pct' => $zeile->trimming_loss_pct,
                    'cooking_loss_pct' => $zeile->cooking_loss_pct,
                    'cooking_loss_source' => $zeile->cooking_loss_source,
                    'match_method' => 'gp_v2_fk',
                    'auto_ground' => false,
                ]]);
            } else {
                $basis = $bestehend;
            }
            app(\Platform\FoodAlchemist\Services\Rezept\RezeptGraphService::class)->sichereKante((int) $gericht->id, (int) $basis->id);   // 1.1e: Sperre + Zyklusprüfung in dieser Transaktion

            // Umhängen wie HardstopResolveService::binde — gezielter Row-Update, Menge/Einheit/Rolle/Notiz bleiben.
            $zeile->update([
                'gp_id' => null,
                'referenced_recipe_id' => (int) $basis->id,
                'display_name' => (string) $basis->name,
                'raw_text' => (string) $basis->name,
                'match_method' => MatchMethod::RecipeRef,
                'match_confidence' => null,
            ]);
            $recompute->recomputeAndPropagate((int) $gericht->id);

            return ['recipe_id' => (int) $basis->id, 'name' => (string) $basis->name, 'neu' => $neu,
                'gericht_id' => (int) $gericht->id, 'ingredient_id' => (int) $zeile->id];
        });
    }
}
