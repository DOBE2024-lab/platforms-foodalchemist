<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\Team;
use Platform\Core\Services\ContextFileService;
use Platform\FoodAlchemist\Support\BildVerweise;

class FoodAlchemistMediaService
{
    public function __construct(
        private ContextFileService $contextFiles,
    ) {
    }

    /** Standard der langen Kante (4K: der Wandmonitor zeigt Fotos groß); einstellbar über foodalchemist.media.max_kante. */
    public const MAX_KANTE = 3840;

    public static function maxKante(): int
    {
        return max(1, (int) config('foodalchemist.media.max_kante', self::MAX_KANTE));
    }

    /**
     * @return array{context_file_id:int,path:string}
     */
    /**
     * Nicht-Bild-Datei (z. B. Schriftdatei) ablegen — ohne Verkleinerung, sonst wie storeImage.
     *
     * @return array{context_file_id:int, path:string}
     */
    public function storeFile(UploadedFile $file, Team $team, string $contextType, int $contextId, string $folder): array
    {
        $result = $this->contextFiles->uploadForContext($file, $contextType, $contextId, [
            'team_id' => $team->id,
            'folder' => $folder,
            'keep_original' => true,
            'generate_variants' => false,
        ]);

        return ['context_file_id' => (int) $result['id'], 'path' => (string) $result['path']];
    }

    public function storeImage(UploadedFile $file, Team $team, string $contextType, int $contextId, string $folder): array
    {
        $result = $this->contextFiles->uploadForContext($this->verkleinert($file), $contextType, $contextId, [
            'team_id' => $team->id,
            'folder' => $folder,
            'keep_original' => false,
            'generate_variants' => false,
        ]);

        return [
            'context_file_id' => (int) $result['id'],
            'path' => (string) $result['path'],
        ];
    }

    /**
     * Vorschritt vor dem Core (der speichert Rasterbilder als WebP in Originalgröße): Bilder mit langer Kante über
     * maxKante() werden auf maxKante() verkleinert, Seitenverhältnis bleibt, die EXIF-Ausrichtung wird angewendet (Handyfotos
     * liegen sonst quer). Kleinere Bilder, Nicht-Bilder, SVG/GIF und Formate, die GD nicht lesen kann (z. B. HEIC), gehen
     * unverändert durch. Dieselbe Bibliothek wie der Core (Intervention Image, GD).
     */
    public function verkleinert(UploadedFile $file): UploadedFile
    {
        $mime = strtolower((string) $file->getMimeType());
        $pfad = (string) $file->getRealPath();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $pfad === '') {
            return $file;
        }
        $masse = @getimagesize($pfad);
        if ($masse === false || max((int) $masse[0], (int) $masse[1]) <= self::maxKante()) {
            return $file;
        }
        try {
            $bild = ImageManager::gd()->read($pfad);   // autoOrientation: EXIF-Drehung wird beim Lesen angewendet
            $bild->scaleDown(self::maxKante(), self::maxKante());
            $kodiert = match ($mime) {
                'image/png' => $bild->toPng(),
                'image/webp' => $bild->toWebp(90),
                default => $bild->toJpeg(90),
            };
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::info('Bild nicht verkleinert, unverändert gespeichert: '.$e->getMessage(), ['mime' => $mime]);

            return $file;
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'fa-klein-');
        file_put_contents($tmp, (string) $kodiert);
        register_shutdown_function(static fn () => @unlink($tmp));

        return new UploadedFile($tmp, $file->getClientOriginalName(), $mime, null, true);
    }

    /**
     * Bilddatei in den Speicher eines anderen Teams kopieren (Kündigung/Eigene Kopie eines Abo-Objekts, Spec 82 D5): Quelle = ContextFile des Masters
     * bzw. Legacy-Pfad auf der public-Disk. Fehlt die Datei, kommt `null` (kein Abbruch — der Aufrufer meldet einen Hinweis).
     *
     * @return array{context_file_id:int,path:string}|null
     */
    public function kopiereBild(?int $contextFileId, ?string $legacyPath, Team $ziel, string $contextType, int $contextId, string $folder): ?array
    {
        $bytes = null;
        $name = 'bild.jpg';
        $mime = 'image/jpeg';
        try {
            $quelle = $contextFileId ? ContextFile::find($contextFileId) : null;
            if ($quelle !== null && Storage::disk($quelle->disk)->exists($quelle->path)) {
                $bytes = Storage::disk($quelle->disk)->get($quelle->path);
                $name = (string) ($quelle->original_name ?: basename($quelle->path));
                $mime = (string) ($quelle->mime_type ?: 'image/jpeg');
            } elseif (trim((string) $legacyPath) !== '' && Storage::disk('public')->exists((string) $legacyPath)) {
                $bytes = Storage::disk('public')->get((string) $legacyPath);
                $name = basename((string) $legacyPath);
                $mime = (string) (Storage::disk('public')->mimeType((string) $legacyPath) ?: 'image/jpeg');
            }
            if ($bytes === null) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'fa-bild-');
            file_put_contents($tmp, $bytes);
            try {
                return $this->storeImage(new UploadedFile($tmp, $name, $mime, null, true), $ziel, $contextType, $contextId, $folder);
            } finally {
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Bild konnte nicht kopiert werden: '.$e->getMessage(), ['context_file_id' => $contextFileId, 'ziel_team' => $ziel->id]);

            return null;
        }
    }

    /**
     * Bild-Löschschutz (CQ PR 1): Datei nur löschen, wenn sie keine andere Zeile mehr nutzt (BildVerweise::REGISTER — alle Teams,
     * gelöschte Zeilen zählen mit). Kopien und Fassungen teilen die Datei mit ihrer Quelle; noch genutzt → nichts tun, der Aufrufer
     * leert nur seine Spalte. Unbenutzt, aber Datei eines anderen Teams → still überspringen (statt 403), nur Log.
     *
     * @param  list<array{tabelle:string, id:int, spalte?:?string, schluessel?:?string}>  $ausser  eigene Verweise des Aufrufers
     *                                                                                           (BildVerweise::eigen), zählen nicht als Nutzung
     */
    public function loescheWennUnbenutzt(?int $contextFileId, ?string $legacyPath, Team $team, array $ausser = []): void
    {
        $contextFileId = $contextFileId ?: null;
        $legacyPath = trim((string) $legacyPath);
        if ($contextFileId === null && $legacyPath === '') {
            return;
        }
        if ($this->wirdGenutzt($contextFileId, $legacyPath, isset($ausser['tabelle']) ? [$ausser] : $ausser)) {
            return;
        }
        if ($contextFileId !== null) {
            $datei = ContextFile::query()->withoutGlobalScopes()->find($contextFileId);
            if ($datei === null) {
                return;   // Datei schon weg — nichts zu löschen
            }
            if ((int) $datei->team_id !== (int) $team->id) {
                Log::info('Bild nicht gelöscht: Datei gehört einem anderen Team.', [
                    'context_file_id' => $contextFileId, 'team_id' => $team->id, 'datei_team_id' => $datei->team_id,
                ]);

                return;
            }
        }
        $this->delete($contextFileId, $legacyPath, $team);
    }

    /**
     * Nutzt irgendeine Zeile aus BildVerweise::REGISTER die Datei (context_file_id ODER Pfad)? Ohne Team-Sicht und globale Scopes,
     * gelöschte Zeilen eingeschlossen. $ausser nimmt die eigenen Verweise des Aufrufers aus.
     *
     * @param  list<array{tabelle:string, id:int, spalte?:?string, schluessel?:?string}>  $ausser
     */
    public function wirdGenutzt(?int $contextFileId, string $pfad, array $ausser = []): bool
    {
        foreach (BildVerweise::REGISTER as $tabelle => $verweise) {
            foreach ($verweise as $verweis) {
                if (isset($verweis['json'])) {
                    if ($this->jsonNutzt($tabelle, $verweis['json'], $contextFileId, $pfad, $ausser)) {
                        return true;
                    }

                    continue;
                }
                [$idSpalte, $pfadSpalte] = $verweis;
                $eigene = array_map(fn ($a) => (int) $a['id'], array_filter($ausser, fn ($a) => $a['tabelle'] === $tabelle
                    && in_array($a['spalte'] ?? null, [null, $idSpalte, $pfadSpalte], true)));
                $genutzt = DB::table($tabelle)
                    ->where(function ($w) use ($idSpalte, $pfadSpalte, $contextFileId, $pfad) {
                        if ($contextFileId !== null) {
                            $w->orWhere($idSpalte, $contextFileId);
                        }
                        if ($pfad !== '') {
                            $w->orWhere($pfadSpalte, $pfad);
                        }
                    })
                    ->when($eigene !== [], fn ($q) => $q->whereNotIn('id', array_values($eigene)))
                    ->exists();
                if ($genutzt) {
                    return true;
                }
            }
        }

        return false;
    }

    /** JSON-Spalte: Einträge {context_file_id, path} in beliebiger Tiefe; die Prüfung läuft in PHP (JSON-Text je DB verschieden). */
    private function jsonNutzt(string $tabelle, string $spalte, ?int $contextFileId, string $pfad, array $ausser): bool
    {
        $eigene = array_values(array_filter($ausser, fn ($a) => $a['tabelle'] === $tabelle && in_array($a['spalte'] ?? null, [null, $spalte], true)));
        foreach (DB::table($tabelle)->whereNotNull($spalte)->select(['id', $spalte])->lazyById(200) as $zeile) {
            $daten = json_decode((string) $zeile->{$spalte}, true);
            foreach (is_array($daten) ? $daten : [] as $schluessel => $eintrag) {
                foreach ($eigene as $a) {
                    if ((int) $a['id'] === (int) $zeile->id && (($a['schluessel'] ?? null) === null || (string) $a['schluessel'] === (string) $schluessel)) {
                        continue 2;
                    }
                }
                if ($this->eintragNutzt($eintrag, $contextFileId, $pfad)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function eintragNutzt(mixed $eintrag, ?int $contextFileId, string $pfad): bool
    {
        if (! is_array($eintrag)) {
            return false;
        }
        if (($contextFileId !== null && (int) ($eintrag['context_file_id'] ?? 0) === $contextFileId)
            || ($pfad !== '' && is_string($eintrag['path'] ?? null) && $eintrag['path'] === $pfad)) {
            return true;
        }
        foreach ($eintrag as $kind) {
            if ($this->eintragNutzt($kind, $contextFileId, $pfad)) {
                return true;
            }
        }

        return false;
    }

    /** Direkt löschen, OHNE Nutzungsprüfung — nur für Dateien, die nie geteilt werden (Schriften, BildVerweise::AUSNAHMEN). */
    public function delete(?int $contextFileId, ?string $legacyPath, Team $team): void
    {
        if ($contextFileId !== null) {
            $this->contextFiles->delete($contextFileId, $team->id);

            return;
        }

        $legacyPath = trim((string) $legacyPath);
        if ($legacyPath !== '' && Storage::disk('public')->exists($legacyPath)) {
            Storage::disk('public')->delete($legacyPath);
        }
    }

    public function dataUri(?int $contextFileId, ?string $legacyPath): ?string
    {
        $file = $contextFileId ? ContextFile::find($contextFileId) : null;
        if ($file !== null && Storage::disk($file->disk)->exists($file->path)) {
            $mime = $file->mime_type ?: (Storage::disk($file->disk)->mimeType($file->path) ?: 'image/png');
            $bytes = Storage::disk($file->disk)->get($file->path);

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        }

        $legacyPath = trim((string) $legacyPath);
        if ($legacyPath === '' || ! Storage::disk('public')->exists($legacyPath)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($legacyPath) ?: 'image/png';
        $bytes = Storage::disk('public')->get($legacyPath);

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    public function url(?int $contextFileId, ?string $legacyPath): string
    {
        $file = $contextFileId ? ContextFile::find($contextFileId) : null;
        if ($file !== null) {
            return $file->url;
        }

        return Storage::disk('public')->url((string) $legacyPath);
    }
}
