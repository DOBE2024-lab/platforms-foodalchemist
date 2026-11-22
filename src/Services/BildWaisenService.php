<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Platform\FoodAlchemist\Support\BildVerweise;

/**
 * Waisen-Bilddateien finden und in Quarantäne legen (Folge aus dem Bild-Löschschutz #901/#907, Befehl `foodalchemist:waisen`).
 *
 * Die Nutzung prüft ausschließlich {@see FoodAlchemistMediaService::wirdGenutzt()} (BildVerweise::REGISTER, alle Teams, gelöschte
 * Zeilen und veröffentlichte Snapshots eingeschlossen) — keine zweite Prüfung, damit Löschschutz und Aufräumen nie auseinanderlaufen.
 *
 * Arten:
 *  - `kontextdatei`: Core-`context_files` mit FA-context_type und Bild-mime, älter als die Schwelle, ungenutzt. Nur diese werden mit
 *    --apply verschoben (nachweislich FA). Belege/Dokumente (BildVerweise::AUSNAHMEN mit context_file_id) sind keine Kandidaten.
 *  - `altpfad`: Dateien auf `public` in den Ordnern, die die Pfad-Spalten des Registers benutzen, ohne context_files-Zeile, ungenutzt.
 *    Nur Hinweis — die Disk gehört dem ganzen Host (Kurator 22.11.2026), wird nie verschoben.
 *  - `zeile_ohne_datei`: FA-context_files, deren Datei fehlt. Nur Information.
 *
 * Quarantäne verschiebt die Datei (samt Varianten) auf derselben Disk nach `fa-quarantaene/<lauf>/<pfad>`, die DB-Zeile bleibt; ein
 * Journal hält jeden Schritt fest, der Rückweg legt alles zurück (nie überschreibend). Endgültiges Löschen gibt es hier nicht.
 */
class BildWaisenService
{
    public const QUARANTAENE = 'fa-quarantaene';

    public function __construct(private FoodAlchemistMediaService $media) {}

    /**
     * @return array{kontextdatei: list<array>, altpfad: list<array>, zeile_ohne_datei: list<array>}
     */
    public function suche(int $aelterAlsTage, ?int $teamId = null, ?int $limit = null): array
    {
        $grenze = now()->subDays(max(0, $aelterAlsTage));
        $out = ['kontextdatei' => [], 'altpfad' => [], 'zeile_ohne_datei' => []];
        $belege = $this->belegIds();

        $zeilen = DB::table('context_files')->where('context_type', 'like', 'foodalchemist%')->where('mime_type', 'like', 'image/%')
            ->where('created_at', '<=', $grenze)->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
            ->select(['id', 'team_id', 'context_type', 'disk', 'path', 'file_size', 'created_at']);
        foreach ($zeilen->lazyById(200) as $z) {
            if (isset($belege[(int) $z->id])) {
                continue;
            }
            $eintrag = ['context_file_id' => (int) $z->id, 'team_id' => (int) $z->team_id, 'context_type' => (string) $z->context_type,
                'disk' => (string) $z->disk, 'pfad' => (string) $z->path, 'bytes' => (int) $z->file_size, 'angelegt' => (string) $z->created_at];
            if (! Storage::disk($z->disk)->exists($z->path)) {
                $out['zeile_ohne_datei'][] = $eintrag;

                continue;
            }
            if (! $this->media->wirdGenutzt((int) $z->id, (string) $z->path)) {
                $eintrag['varianten'] = DB::table('context_file_variants')->where('context_file_id', $z->id)->get(['disk', 'path'])
                    ->map(fn ($v) => ['disk' => (string) $v->disk, 'pfad' => (string) $v->path])->all();
                $out['kontextdatei'][] = $eintrag;
                if ($limit !== null && count($out['kontextdatei']) >= $limit) {
                    break;
                }
            }
        }

        if ($teamId === null) {
            $out['altpfad'] = $this->altpfade($grenze->getTimestamp(), $limit);
        }

        return $out;
    }

    /** Dateien auf `public` in den Register-Ordnern ohne context_files-Zeile und ohne Nutzung (nur Hinweis). */
    private function altpfade(int $grenze, ?int $limit): array
    {
        $disk = Storage::disk('public');
        $out = [];
        foreach ($this->altOrdner() as $ordner) {
            foreach ($disk->files($ordner) as $pfad) {
                if (str_starts_with($pfad, self::QUARANTAENE . '/') || $disk->lastModified($pfad) > $grenze
                    || DB::table('context_files')->where('disk', 'public')->where('path', $pfad)->exists()
                    || $this->media->wirdGenutzt(null, $pfad)) {
                    continue;
                }
                $out[] = ['disk' => 'public', 'pfad' => $pfad, 'bytes' => (int) $disk->size($pfad)];
                if ($limit !== null && count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** Ordner, die die Pfad-Spalten des Registers heute benutzen (aus den Daten, keine Liste im Code). @return list<string> */
    private function altOrdner(): array
    {
        $ordner = [];
        foreach (BildVerweise::REGISTER as $tabelle => $verweise) {
            foreach ($verweise as $verweis) {
                if (isset($verweis['json']) || ! Schema::hasColumn($tabelle, $verweis[1])) {
                    continue;
                }
                foreach (DB::table($tabelle)->whereNotNull($verweis[1])->where($verweis[1], '!=', '')->distinct()->pluck($verweis[1]) as $pfad) {
                    $d = dirname((string) $pfad);
                    if ($d !== '.' && $d !== '') {
                        $ordner[$d] = true;
                    }
                }
            }
        }
        ksort($ordner);

        return array_keys($ordner);
    }

    /** context_file_ids, auf die eine Beleg-/Dokument-Spalte (BildVerweise::AUSNAHMEN) zeigt — nie Bild-Kandidaten. @return array<int, true> */
    private function belegIds(): array
    {
        $ids = [];
        foreach (array_keys(BildVerweise::AUSNAHMEN) as $schluessel) {
            [$tabelle, $spalte] = explode('.', $schluessel, 2);
            if (! str_ends_with($spalte, 'context_file_id') || ! Schema::hasColumn($tabelle, $spalte)) {
                continue;
            }
            foreach (DB::table($tabelle)->whereNotNull($spalte)->distinct()->pluck($spalte) as $id) {
                $ids[(int) $id] = true;
            }
        }

        return $ids;
    }

    /**
     * Kontextdateien in Quarantäne verschieben. Vor jedem Verschieben erneut geprüft (zwischen Bericht und Apply wieder genutzt → bleibt).
     *
     * @param  list<array>  $kandidaten  aus suche()['kontextdatei']
     * @return array{lauf: string, journal: string, verschoben: int, bytes: int, uebersprungen: list<array>}
     */
    public function quarantaene(array $kandidaten, string $journalOrdner): array
    {
        $lauf = now()->format('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        $journal = ['lauf' => $lauf, 'erstellt' => now()->toIso8601String(), 'eintraege' => []];
        $uebersprungen = [];
        $bytes = 0;
        foreach ($kandidaten as $k) {
            if ($this->media->wirdGenutzt($k['context_file_id'], $k['pfad'])) {
                $uebersprungen[] = $k + ['grund' => 'wieder genutzt'];

                continue;
            }
            $dateien = [];
            foreach ([['disk' => $k['disk'], 'pfad' => $k['pfad']], ...($k['varianten'] ?? [])] as $d) {
                $ziel = self::QUARANTAENE . '/' . $lauf . '/' . ltrim($d['pfad'], '/');
                if (Storage::disk($d['disk'])->exists($d['pfad']) && Storage::disk($d['disk'])->move($d['pfad'], $ziel)) {
                    $dateien[] = ['disk' => $d['disk'], 'von' => $d['pfad'], 'nach' => $ziel];
                }
            }
            if ($dateien !== []) {
                $journal['eintraege'][] = ['art' => 'kontextdatei', 'context_file_id' => $k['context_file_id'], 'team_id' => $k['team_id'],
                    'bytes' => $k['bytes'], 'dateien' => $dateien];
                $bytes += $k['bytes'];
            }
        }
        File::ensureDirectoryExists($journalOrdner);
        $pfad = rtrim($journalOrdner, '/') . '/' . $lauf . '.json';
        File::put($pfad, json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['lauf' => $lauf, 'journal' => $pfad, 'verschoben' => count($journal['eintraege']), 'bytes' => $bytes, 'uebersprungen' => $uebersprungen];
    }

    /**
     * Rückweg aus einem Journal: jede Datei zurück an ihren Ursprungsort; liegt dort schon etwas, wird nichts überschrieben (Konflikt).
     *
     * @return array{zurueck: int, konflikte: list<string>, fehlend: list<string>}
     */
    public function rueckweg(string $journalPfad): array
    {
        $journal = json_decode((string) File::get($journalPfad), true);
        $out = ['zurueck' => 0, 'konflikte' => [], 'fehlend' => []];
        foreach ((array) ($journal['eintraege'] ?? []) as $e) {
            foreach ((array) ($e['dateien'] ?? []) as $d) {
                $disk = Storage::disk($d['disk']);
                if ($disk->exists($d['von'])) {
                    $out['konflikte'][] = $d['disk'] . ':' . $d['von'];
                } elseif (! $disk->exists($d['nach'])) {
                    $out['fehlend'][] = $d['disk'] . ':' . $d['nach'];
                } elseif ($disk->move($d['nach'], $d['von'])) {
                    $out['zurueck']++;
                }
            }
        }

        return $out;
    }
}
