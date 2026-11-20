<?php

namespace Platform\FoodAlchemist\Support;

use Livewire\Component;

/**
 * Eine Grenze und eine Sprache für jeden Bild-Upload im Modul (Titelbild, Logo, Kapitelbild, Galerie, Rezeptfoto …).
 *
 * Warum 14 MB: Auf io lässt Livewire vorab höchstens 15 MB durch (temporary_file_upload.rules max:15360; PHP und nginx
 * 20 MB). Liegt unsere Grenze knapp darunter, greift bei zu großen Bildern unsere Meldung und nicht die rohe Livewire-
 * Prüfung. Große Bilder werden beim Speichern ohnehin verkleinert (FoodAlchemistMediaService::verkleinert).
 *
 * Warum eigene Meldungen: Die Host-App hat keine lang/de-Dateien, ohne Meldung erscheint der Rohschlüssel
 * (»validation.max.file«). Deshalb gibt jede Prüfung ihre deutschen Meldungen selbst mit.
 *
 * Neue Bild-Uploads prüfen nur über BildUpload::pruefen() bzw. regel()/meldungen() — kein »image|max:« im Livewire-Code
 * (Wächter: BildUploadGrenzeTest).
 */
final class BildUpload
{
    /** 14 MB in KB (Laravel-Regel max: zählt bei Dateien in Kilobyte). */
    public const MAX_KB = 14336;

    public static function regel(bool $pflicht = false): string
    {
        return ($pflicht ? 'required|' : '') . 'image|max:' . self::MAX_KB;
    }

    /** @return array<string, string> Meldungen für das Feld (auch mit Platzhalter, z. B. »galerie.*«) */
    public static function meldungen(string $feld): array
    {
        $mb = DateiUpload::mb(self::MAX_KB);

        return [
            "{$feld}.required" => 'Bitte zuerst ein Bild wählen.',
            "{$feld}.image" => 'Bitte eine Bilddatei wählen (JPG, PNG oder WebP).',
            "{$feld}.max" => "Das Bild darf höchstens {$mb} MB groß sein.",
            "{$feld}.uploaded" => "Das Bild konnte nicht hochgeladen werden. Bitte erneut versuchen (höchstens {$mb} MB).",
        ];
    }

    /** Prüft das Upload-Feld einer Komponente; bei Fehler wirft Livewire wie gewohnt (Meldung landet unter dem Feld). */
    public static function pruefen(Component $komponente, string $feld, bool $pflicht = false): void
    {
        $komponente->validate([$feld => self::regel($pflicht)], self::meldungen($feld));
    }
}
