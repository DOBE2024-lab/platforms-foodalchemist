<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

use Illuminate\Http\UploadedFile;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\Plattform\FoodAlchemistMediaService;

/**
 * Spec 43 (Bild-Epic) · Kapitel-Bild und Galerie eines Foodbook-Kapitels (98 · 3.3 S4-1, aus FoodbookService verschoben).
 * Das Kapitel-Bild überschreibt das Concept-Titelbild im Kapitel-Band. Nutzt die Basis, nie die Fassade.
 */
final class FoodbookKapitelBild
{
    public function __construct(private FoodbookBasis $basis) {}

    public function setKapitelImage(Team $team, int $kapitelId, UploadedFile $file): string
    {
        $k = $this->basis->ownedKapitel($team, $kapitelId);
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($k->image_context_file_id, (string) $k->image_path, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($k, 'image_path')]);
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.foodbook_chapter', $kapitelId, "foodalchemist/chapter/{$kapitelId}",
        );
        $k->update(['image_context_file_id' => $media['context_file_id'], 'image_path' => $media['path']]);

        return $media['path'];
    }

    public function clearKapitelImage(Team $team, int $kapitelId): FoodAlchemistFoodbookKapitel
    {
        $k = $this->basis->ownedKapitel($team, $kapitelId);
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($k->image_context_file_id, (string) $k->image_path, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($k, 'image_path')]);
        $k->update(['image_context_file_id' => null, 'image_path' => null]);

        return $k->refresh();
    }

    /** Weiteres Galeriebild (neben dem Kapitel-Bild) ans Foodbook-Kapitel hängen. */
    public function addKapitelGalleryImage(Team $team, int $kapitelId, UploadedFile $file): \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage
    {
        $k = $this->basis->ownedKapitel($team, $kapitelId);
        $media = app(FoodAlchemistMediaService::class)->storeImage(
            $file, $team, 'foodalchemist.foodbook_chapter', $kapitelId, "foodalchemist/chapter/{$kapitelId}/gallery",
        );

        return \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage::create([
            'team_id' => $k->team_id,
            'chapter_id' => $kapitelId,
            'context_file_id' => $media['context_file_id'],
            'path' => $media['path'],
            'sort_order' => (int) $k->images()->max('sort_order') + 1,
        ]);
    }

    public function removeKapitelGalleryImage(Team $team, int $imageId): void
    {
        $img = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage::findOrFail($imageId);
        $this->basis->ownedKapitel($team, (int) $img->chapter_id); // Owner-Guard übers Kapitel
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($img->context_file_id, (string) $img->path, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($img)]);
        $img->delete();
    }
}
