<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Altbestand „GP direkt im Gericht" im VK-Modal (Entscheid Dominique 17.11.2026: ein Gericht enthält nur Basisrezepte).
 * Erwartet von der Komponente: `?int $recipeId`, `?string $fehler`, `int $zutatenVersion`.
 */
trait HatGpUmbauImGericht
{
    /** Altbestand „GP direkt im Gericht" (17.11.2026): editierbarer Name je Zeile (ingredient_id => Name), vorbelegt mit dem Vorschlag. */
    public array $gpUmbauNamen = [];

    /**
     * Reife-Befund gp_im_gericht (Entscheid Dominique 17.11.2026: ein Gericht enthält nur Basisrezepte): aus der GP-Zeile ein
     * Basisrezept (Entwurf) anlegen und die Zeile darauf umhängen — derselbe Service wie foodalchemist.recipe_gp_zu_basisrezept.POST.
     */
    public function gpZeileAlsBasisrezept(int $ingredientId): void
    {
        $team = Auth::user()?->currentTeamRelation;
        if ($team === null || $this->recipeId === null) {
            return;
        }
        $this->fehler = null;
        try {
            $r = app(\Platform\FoodAlchemist\Services\GerichtGpUmbauService::class)
                ->basisrezeptAnlegen($team, $ingredientId, $this->gpUmbauNamen[$ingredientId] ?? null);
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();

            return;
        }
        unset($this->gpUmbauNamen[$ingredientId]);
        $this->zutatenVersion++;                                     // Editor-rows neu vom Server
        $this->dispatch('recipe-gespeichert');
        $this->dispatch('fa-saved', message: ($r['neu'] ? 'Basisrezept angelegt: ' : 'Basisrezept verknüpft: ') . $r['name']);
    }

    /** @return list<array{id: int, gp: string, vorschlag: string, typ_sicher: bool}> */
    private function gpDirektZeilen(?FoodAlchemistRecipe $rezept): array
    {
        if ($rezept === null || ! $rezept->is_sales_recipe) {
            return [];
        }
        $umbau = app(\Platform\FoodAlchemist\Services\GerichtGpUmbauService::class);

        return $umbau->direkteGpZeilen($rezept)->map(function ($z) use ($umbau) {
            $v = $umbau->nameVorschlag($z);
            $this->gpUmbauNamen[(int) $z->id] ??= $v['name'];

            return ['id' => (int) $z->id, 'gp' => (string) ($z->gp?->name ?? $z->display_name ?? $z->raw_text),
                'vorschlag' => $v['name'], 'typ_sicher' => $v['typ_sicher']];
        })->values()->all();
    }
}
