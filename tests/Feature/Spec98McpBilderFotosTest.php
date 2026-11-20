<?php

use Illuminate\Support\Facades\Storage;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
use Platform\FoodAlchemist\Services\RecipeStepService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 98 · 2.3c — Bilder per MCP: foodbooks.IMAGE (Logo, Cover, Kapitelbild, Kapitel-Galerie) und die Schrittfotos
 * recipe_steps.PHOTO_* (Upload, Verknüpfen, Abhängen, Endprodukt, Löschen) mit der Status-Sperre wie recipe_steps.PUT.
 */
function spec98Png(): string
{
    $bild = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($bild);

    return (string) ob_get_clean();
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    Storage::fake('public');
    $this->user = $this->makeUser($this->rootTeam, 'Bilder');
    $this->actingAs($this->user);
    $this->datei = ['base64' => base64_encode(spec98Png()), 'name' => 'bild.png'];
    $this->run = function (string $name, array $args, $team = null) {
        $team ??= $this->rootTeam;
        $user = $team->is($this->rootTeam) ? $this->user : $this->makeUser($team, 'Fremd ' . $team->id);

        return app(ToolRegistry::class)->get($name)->execute($args, new ToolContext($user, $team));
    };
});

// ── foodbooks.IMAGE ─────────────────────────────────────────────────────────

describe('foodbooks.IMAGE', function () {
    beforeEach(function () {
        $this->fb = $this->makeFoodbook($this->rootTeam, 'Katalog');
        $this->kap = $this->makeChapter($this->fb, ['title' => 'Partner']);
    });

    it('setzt Logo und Kapitelbild und entfernt das Logo nur mit confirm', function () {
        $logo = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'logo', 'datei' => $this->datei]);
        expect($logo->success)->toBeTrue()->and($this->fb->refresh()->logo_path)->not->toBeNull();

        $kap = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'kapitel_bild', 'kapitel_id' => $this->kap->id, 'datei' => $this->datei]);
        expect($kap->success)->toBeTrue()->and($this->kap->refresh()->image_path)->not->toBeNull();

        $ohne = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'logo', 'aktion' => 'entfernen']);
        expect($ohne->errorCode)->toBe('CONFIRM_REQUIRED')->and($this->fb->refresh()->logo_path)->not->toBeNull();

        $weg = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'logo', 'aktion' => 'entfernen', 'confirm' => true]);
        expect($weg->success)->toBeTrue()->and($this->fb->refresh()->logo_path)->toBeNull();
    });

    it('Kapitel-Galerie: hinzufügen und mit confirm entfernen', function () {
        $neu = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'kapitel_galerie', 'kapitel_id' => $this->kap->id, 'datei' => $this->datei]);
        expect($neu->success)->toBeTrue();
        $imageId = $neu->data['image_id'];
        expect(FoodAlchemistFoodbookChapterImage::find($imageId))->not->toBeNull();

        $weg = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'kapitel_galerie', 'aktion' => 'entfernen', 'image_id' => $imageId, 'confirm' => true]);
        expect($weg->success)->toBeTrue()->and(FoodAlchemistFoodbookChapterImage::find($imageId))->toBeNull();
    });

    it('Kapitel eines anderen Foodbooks → NOT_FOUND; keine Bilddatei → VALIDATION_ERROR wie in der Oberfläche', function () {
        $anderes = $this->makeChapter($this->makeFoodbook($this->rootTeam, 'Anderes'));
        $r = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'kapitel_bild', 'kapitel_id' => $anderes->id, 'datei' => $this->datei]);
        expect($r->errorCode)->toBe('NOT_FOUND')->and($anderes->refresh()->image_path)->toBeNull();

        $text = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'cover', 'datei' => ['base64' => base64_encode('kein Bild'), 'name' => 'x.txt']]);
        expect($text->errorCode)->toBe('VALIDATION_ERROR')->and($text->error)->toContain('Bilddatei');
    });

    it('D1: ein anderes Team kann die Bilder nicht ändern', function () {
        $r = ($this->run)('foodalchemist.foodbooks.IMAGE', ['id' => $this->fb->id, 'ziel' => 'cover', 'datei' => $this->datei], $this->childA);
        expect($r->errorCode)->toBe('ACCESS_DENIED')->and($this->fb->refresh()->cover_image_path)->toBeNull();
    });
});

// ── recipe_steps.PHOTO_* ────────────────────────────────────────────────────

describe('recipe_steps.PHOTO_*', function () {
    beforeEach(function () {
        $this->rezept = $this->makeRecipe($this->rootTeam, 'Risotto', ['status' => 'draft']);
        app(RecipeStepService::class)->sync($this->rezept, [['text' => 'Zwiebeln schneiden'], ['text' => 'Reis anschwitzen']]);
        [$this->s1, $this->s2] = FoodAlchemistRecipeStep::where('recipe_id', $this->rezept->id)->orderBy('position')->get()->all();
    });

    it('Upload an einen Schritt, verknüpfen, abhängen, Endprodukt, löschen', function () {
        $up = ($this->run)('foodalchemist.recipe_steps.PHOTO_UPLOAD', ['recipe_id' => $this->rezept->id, 'step_id' => $this->s1->id, 'caption' => 'fein', 'datei' => $this->datei]);
        expect($up->success)->toBeTrue()->and($up->data['photo']['step_ids'])->toBe([(int) $this->s1->id]);
        $fotoId = $up->data['photo']['id'];

        $link = ($this->run)('foodalchemist.recipe_steps.PHOTO_LINK', ['recipe_id' => $this->rezept->id, 'step_id' => $this->s2->id, 'photo_id' => $fotoId]);
        expect($link->success)->toBeTrue()->and($link->data['photo']['step_ids'])->toHaveCount(2);
        // idempotent: zweites Verknüpfen hängt nicht ab
        $nochmal = ($this->run)('foodalchemist.recipe_steps.PHOTO_LINK', ['recipe_id' => $this->rezept->id, 'step_id' => $this->s2->id, 'photo_id' => $fotoId]);
        expect($nochmal->data['unveraendert'])->toBeTrue()->and($nochmal->data['photo']['step_ids'])->toHaveCount(2);

        $unlink = ($this->run)('foodalchemist.recipe_steps.PHOTO_UNLINK', ['recipe_id' => $this->rezept->id, 'step_id' => $this->s1->id, 'photo_id' => $fotoId]);
        expect($unlink->data['photo']['step_ids'])->toBe([(int) $this->s2->id]);

        $res = ($this->run)('foodalchemist.recipe_steps.PHOTO_RESULT', ['recipe_id' => $this->rezept->id, 'photo_id' => $fotoId]);
        expect($res->data['result_photo']['is_result'])->toBeTrue();
        $res = ($this->run)('foodalchemist.recipe_steps.PHOTO_RESULT', ['recipe_id' => $this->rezept->id, 'photo_id' => $fotoId]);
        expect($res->data['result_photo']['is_result'])->toBeTrue();   // erneutes Setzen hebt nicht auf

        $ohne = ($this->run)('foodalchemist.recipe_steps.PHOTO_DELETE', ['recipe_id' => $this->rezept->id, 'photo_id' => $fotoId]);
        expect($ohne->errorCode)->toBe('CONFIRM_REQUIRED');
        $weg = ($this->run)('foodalchemist.recipe_steps.PHOTO_DELETE', ['recipe_id' => $this->rezept->id, 'photo_id' => $fotoId, 'confirm' => true]);
        expect($weg->success)->toBeTrue()->and(FoodAlchemistRecipeStepPhoto::find($fotoId))->toBeNull();
    });

    it('Status-Sperre: freigegebene Rezepte sind per MCP gesperrt, mit Hinweis auf die Oberfläche', function () {
        $this->rezept->update(['status' => 'approved']);

        $r = ($this->run)('foodalchemist.recipe_steps.PHOTO_UPLOAD', ['recipe_id' => $this->rezept->id, 'datei' => $this->datei]);

        expect($r->errorCode)->toBe('ACCESS_DENIED')
            ->and($r->error)->toContain('Rezept › Schritte')
            ->and(FoodAlchemistRecipeStepPhoto::where('recipe_id', $this->rezept->id)->count())->toBe(0);
    });

    it('D1 und fremde Schritte: anderes Team → ACCESS_DENIED, Schritt eines anderen Rezepts → NOT_FOUND', function () {
        $r = ($this->run)('foodalchemist.recipe_steps.PHOTO_UPLOAD', ['recipe_id' => $this->rezept->id, 'datei' => $this->datei], $this->childA);
        expect($r->errorCode)->toBe('ACCESS_DENIED');

        $anderes = $this->makeRecipe($this->rootTeam, 'Brühe', ['status' => 'draft']);
        app(RecipeStepService::class)->sync($anderes, [['text' => 'Aufkochen']]);
        $fremderSchritt = FoodAlchemistRecipeStep::where('recipe_id', $anderes->id)->first();
        $r = ($this->run)('foodalchemist.recipe_steps.PHOTO_UPLOAD', ['recipe_id' => $this->rezept->id, 'step_id' => $fremderSchritt->id, 'datei' => $this->datei]);
        expect($r->errorCode)->toBe('NOT_FOUND');
    });
});
