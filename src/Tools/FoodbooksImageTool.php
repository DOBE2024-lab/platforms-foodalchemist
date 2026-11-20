<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\FoodAlchemistMediaService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tools\Concerns\DateiAusArgumenten;

/**
 * 98 · 2.3c: Foodbook-Bilder per MCP — Logo, Cover, Kapitelbild und Kapitel-Galerie setzen bzw. entfernen.
 * Gleicher Schreibpfad wie die Foodbook-Übersicht (FoodbookService → FoodAlchemistMediaService) und dieselbe
 * Bild-Prüfung (BildUpload: Bilddatei, höchstens 14 MB). Kapitel nur aus DIESEM Foodbook; Entfernen nur mit confirm=true.
 */
class FoodbooksImageTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    use DateiAusArgumenten;

    private const ZIELE = ['logo', 'cover', 'kapitel_bild', 'kapitel_galerie'];

    private const AKTIONEN = ['setzen', 'entfernen'];

    public function getName(): string
    {
        return 'foodalchemist.foodbooks.IMAGE';
    }

    public function getDescription(): string
    {
        return 'Setzt oder entfernt Bilder eines team-eigenen Foodbooks. ziel: logo | cover (Foodbook-Branding), '
            . 'kapitel_bild (Kapitel-Hero, kapitel_id), kapitel_galerie (weiteres Kapitelbild; setzen = hinzufügen mit kapitel_id, '
            . 'entfernen = image_id). aktion: setzen (mit datei: base64 – auch data-URL – ODER url; nur Bilddateien JPG/PNG/WebP, '
            . 'höchstens 14 MB) | entfernen (confirm=true Pflicht; löscht die Datei). Kapitel müssen zum angegebenen Foodbook gehören. '
            . 'Setzen ersetzt ein vorhandenes Logo/Cover/Kapitelbild.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Foodbook-Id.'],
                'ziel' => ['type' => 'string', 'enum' => self::ZIELE, 'description' => 'logo | cover | kapitel_bild | kapitel_galerie.'],
                'aktion' => ['type' => 'string', 'enum' => self::AKTIONEN, 'description' => 'setzen (Default) | entfernen.'],
                'kapitel_id' => ['type' => 'integer', 'description' => 'Bei kapitel_bild und kapitel_galerie/setzen: Kapitel dieses Foodbooks.'],
                'image_id' => ['type' => 'integer', 'description' => 'Bei kapitel_galerie/entfernen: Id des Galeriebilds.'],
                'datei' => [
                    'type' => 'object',
                    'description' => 'Bei setzen: Bilddatei als base64 (auch data:image/…;base64,…) ODER url (http/https), dazu optional name.',
                    'properties' => [
                        'base64' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'name' => ['type' => 'string', 'description' => 'Dateiname, z. B. logo.png'],
                    ],
                ],
                'confirm' => ['type' => 'boolean', 'description' => 'Bei entfernen Pflicht: true (die Bilddatei wird gelöscht).'],
            ],
            'required' => ['id', 'ziel'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $arguments['aktion'] ??= 'setzen';
        if (($fehler = $this->enumFehler($arguments)) !== null) {
            return $fehler;
        }
        $ziel = (string) ($arguments['ziel'] ?? '');
        if ($ziel === '') {
            return ToolResult::error('ziel angeben: ' . implode(' | ', self::ZIELE) . '.', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistFoodbook::class, $id, 'Foodbook')) !== null) {
            return $guard;
        }
        $entfernen = $arguments['aktion'] === 'entfernen';
        if ($entfernen && ($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Entfernen erfordert confirm=true (die Bilddatei wird gelöscht).', 'CONFIRM_REQUIRED');
        }

        // Kapitel bzw. Galeriebild müssen zu DIESEM Foodbook gehören (kein Umweg über ein fremdes Kapitel).
        $kapitelId = (int) ($arguments['kapitel_id'] ?? 0);
        $imageId = (int) ($arguments['image_id'] ?? 0);
        if ($ziel === 'kapitel_galerie' && $entfernen) {
            if ($imageId <= 0) {
                return ToolResult::error('image_id ist für kapitel_galerie/entfernen Pflicht.', 'VALIDATION_ERROR');
            }
            $kapitelId = (int) FoodAlchemistFoodbookChapterImage::whereKey($imageId)->value('chapter_id');
            if ($kapitelId <= 0) {
                return ToolResult::error('Galeriebild nicht vorhanden.', 'NOT_FOUND');
            }
        }
        if (str_starts_with($ziel, 'kapitel_') && ($kapitelFehler = $this->kapitelImFoodbook($id, $kapitelId)) !== null) {
            return $kapitelFehler;
        }

        $svc = app(FoodbookService::class);
        try {
            if ($entfernen) {
                match ($ziel) {
                    'logo' => $svc->clearLogo($team, $id),
                    'cover' => $svc->clearCover($team, $id),
                    'kapitel_bild' => $svc->clearKapitelImage($team, $kapitelId),
                    'kapitel_galerie' => $svc->removeKapitelGalleryImage($team, $imageId),
                };

                return ToolResult::success(array_filter([
                    'id' => $id, 'ziel' => $ziel, 'aktion' => 'entfernen',
                    'kapitel_id' => $kapitelId ?: null, 'image_id' => $imageId ?: null, 'entfernt' => true,
                ], fn ($v) => $v !== null));
            }

            $datei = $this->dateiAusArgumenten(is_array($arguments['datei'] ?? null) ? $arguments['datei'] : null);
            if ($datei === null) {
                return ToolResult::error('datei.base64 oder datei.url angeben.', 'VALIDATION_ERROR');
            }
            if (($bildFehler = $this->bildFehler($datei)) !== null) {
                return ToolResult::error($bildFehler, 'VALIDATION_ERROR');
            }
            $ergebnis = $this->setzen($team, $svc, $ziel, $id, $kapitelId, $datei);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Ziel nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        } finally {
            $this->dateiAufraeumen();
        }

        return ToolResult::success(array_filter($ergebnis + [
            'id' => $id, 'ziel' => $ziel, 'aktion' => 'setzen', 'kapitel_id' => $kapitelId ?: null,
            'hinweis' => 'Im öffentlichen Link sichtbar nach erneutem Veröffentlichen (foodalchemist.foodbook_presentation.PUBLISH).',
        ], fn ($v) => $v !== null));
    }

    /** @return array{path:string, url:?string, image_id?:int} */
    private function setzen(Team $team, FoodbookService $svc, string $ziel, int $id, int $kapitelId, \Illuminate\Http\UploadedFile $datei): array
    {
        $media = app(FoodAlchemistMediaService::class);
        if ($ziel === 'kapitel_galerie') {
            $bild = $svc->addKapitelGalleryImage($team, $kapitelId, $datei);

            return ['image_id' => (int) $bild->id, 'path' => (string) $bild->path, 'url' => $media->url($bild->context_file_id !== null ? (int) $bild->context_file_id : null, (string) $bild->path)];
        }
        $pfad = match ($ziel) {
            'logo' => $svc->storeLogo($team, $id, $datei),
            'cover' => $svc->storeCover($team, $id, $datei),
            'kapitel_bild' => $svc->setKapitelImage($team, $kapitelId, $datei),
        };
        $contextFileId = match ($ziel) {
            'logo' => FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($id)->value('logo_context_file_id'),
            'cover' => FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($id)->value('cover_context_file_id'),
            'kapitel_bild' => FoodAlchemistFoodbookKapitel::whereKey($kapitelId)->value('image_context_file_id'),
        };

        return ['path' => $pfad, 'url' => $media->url($contextFileId !== null ? (int) $contextFileId : null, $pfad)];
    }

    private function kapitelImFoodbook(int $foodbookId, int $kapitelId): ?ToolResult
    {
        if ($kapitelId <= 0) {
            return ToolResult::error('kapitel_id ist für Kapitelbilder Pflicht.', 'VALIDATION_ERROR');
        }
        if (! FoodAlchemistFoodbookKapitel::whereKey($kapitelId)->where('foodbook_id', $foodbookId)->exists()) {
            return ToolResult::error("Kapitel {$kapitelId} gehört nicht zu Foodbook {$foodbookId}.", 'NOT_FOUND');
        }

        return null;
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'bild', 'logo', 'cover', 'kapitel', 'galerie', 'upload', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'external_io',
            'side_effects' => ['creates', 'updates', 'deletes'],
            'related_tools' => ['foodalchemist.foodbooks.BRANDING', 'foodalchemist.foodbook_presentation.PUBLISH', 'foodalchemist.bilder.UPLOAD'],
            'examples' => ['Setze https://…/logo.png als Logo von Foodbook 5.', 'Entferne das Kapitelbild von Kapitel 12 in Foodbook 5 (confirm=true).'],
        ];
    }
}
