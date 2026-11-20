<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Deutsche Meldungen für Datei-Uploads, die keine Bilder sind (CSV, PDF-Import, Preislisten, Schriften, Belege).
 * Die Grenzen bleiben je Stelle, wie sie sind; hier kommt nur die Sprache her — die Host-App hat keine lang/de-Dateien,
 * sonst stünde der Rohschlüssel (»validation.max.file«) unter dem Feld. Bilder: BildUpload.
 */
final class DateiUpload
{
    /** Rohschlüssel, die beim Hochladen ohne eigene Meldung durchrutschen (v. a. aus Livewires Vorab-Prüfung). */
    private const ROH = [
        'validation.max.file', 'validation.uploaded', 'validation.image', 'validation.file',
        'validation.mimes', 'validation.mimetypes', 'validation.extensions',
    ];

    /** 14336 → »14«, 1536 → »1,5« */
    public static function mb(int $kb): string
    {
        return str_replace('.', ',', (string) round($kb / 1024, 1));
    }

    /**
     * @param  string|null  $typen  lesbare Liste für die Typ-Meldung, z. B. »CSV oder TXT«
     * @return array<string, string>
     */
    public static function meldungen(string $feld, int $maxKb, ?string $typen = null): array
    {
        $mb = self::mb($maxKb);
        $typ = 'Dieser Dateityp wird nicht unterstützt.' . ($typen !== null ? " Erlaubt: {$typen}." : '');

        return [
            "{$feld}.required" => 'Bitte zuerst eine Datei wählen.',
            "{$feld}.file" => 'Bitte eine Datei wählen.',
            "{$feld}.max" => "Die Datei darf höchstens {$mb} MB groß sein.",
            "{$feld}.mimes" => $typ,
            "{$feld}.mimetypes" => $typ,
            "{$feld}.uploaded" => "Die Datei konnte nicht hochgeladen werden. Bitte erneut versuchen (höchstens {$mb} MB).",
        ];
    }

    /**
     * Netz für die Fälle, die keine FA-Regel erreicht: Livewire prüft jede Datei vorab mit der Host-Konfiguration
     * (io: max:15360) und meldet dann roh »validation.max.file« bzw. englisch »The x failed to upload.«. Hier werden
     * nur Upload-Rohschlüssel in FA-Komponenten übersetzt; vorhandene Meldungen bleiben unberührt.
     */
    public static function rohschluesselUebersetzen(object $komponente, \Throwable $fehler): void
    {
        if (! $komponente instanceof Component || ! $fehler instanceof ValidationException
            || ! str_starts_with($komponente::class, 'Platform\\FoodAlchemist\\')) {
            return;
        }
        $bag = $fehler->validator->errors();
        $neu = new MessageBag;
        $geaendert = false;
        foreach ($bag->messages() as $feld => $meldungen) {
            foreach ($meldungen as $meldung) {
                $deutsch = self::deutsch((string) $meldung, (string) $feld);
                $geaendert = $geaendert || $deutsch !== $meldung;
                $neu->add($feld, $deutsch);
            }
        }
        if ($geaendert) {
            foreach (array_keys($bag->messages()) as $feld) {
                $bag->forget($feld);
            }
            $bag->merge($neu);
        }
    }

    private static function deutsch(string $meldung, string $feld): string
    {
        $fehlgeschlagen = preg_match('/^The .+ failed to upload\.$/', $meldung) === 1;
        if (! $fehlgeschlagen && ! in_array($meldung, self::ROH, true)) {
            return $meldung;
        }
        $mb = self::mb(self::livewireGrenzeKb());

        return match (true) {
            $meldung === 'validation.max.file' => "Die Datei ist zu groß (höchstens {$mb} MB je Datei).",
            $meldung === 'validation.image' => 'Bitte eine Bilddatei wählen (JPG, PNG oder WebP).',
            in_array($meldung, ['validation.mimes', 'validation.mimetypes', 'validation.extensions'], true) => 'Dieser Dateityp wird nicht unterstützt.',
            default => "Die Datei konnte nicht hochgeladen werden. Bitte erneut versuchen (höchstens {$mb} MB je Datei).",
        };
    }

    /** Livewires Vorab-Grenze aus der Host-Konfiguration; ohne Eintrag gilt Livewires Standard 12 MB. */
    public static function livewireGrenzeKb(): int
    {
        $regeln = config('livewire.temporary_file_upload.rules') ?? ['required', 'file', 'max:12288'];
        foreach (is_array($regeln) ? $regeln : explode('|', (string) $regeln) as $regel) {
            if (is_string($regel) && preg_match('/^max:(\d+)$/', $regel, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 12288;
    }
}
