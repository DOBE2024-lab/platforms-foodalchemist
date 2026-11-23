<?php

namespace Platform\FoodAlchemist\Support\Planung;

use Platform\FoodAlchemist\Services\Stt\SttServiceContract;
use Platform\FoodAlchemist\Support\VoiceFehlerText;
use Platform\FoodAlchemist\Support\VoiceMime;

/**
 * Spec 85 C1 PR-5 · Diktat in der Planungs-Leitstelle, aus `Livewire\Planung\Index` herausgelöst (Entscheid Kurator zu E3/E4).
 * Zustandsfrei, ohne #[On] (Livewire-Empfänger bleiben in den Komponenten).
 *
 * Diktat ist ein Nachtrag: gesprochener Text wird ANGEHÄNGT, nie ersetzt — ein versehentlich überschriebenes Briefing wäre nicht
 * wiederherstellbar. Reines STT, kein Tool-Loop: das Briefing soll exakt das sein, was der Mensch gesagt hat.
 *
 * Wege: Die fünf Ausgabeform-Briefings (Planung\AusBrief) diktieren per Recorder (`briefAudio`, Ziel aus dem Blade, also vom
 * CLIENT → Whitelist {@see ZIELE}). Die drei Erstell-Reiter (Planung\Brief) diktieren nur über das Agenten-Panel
 * (`voice.diktat-transkribiert`, Kurskorrektur 2026-09-19 „pro Reiter genau EINE Diktierfunktion“) und nutzen hier nur {@see anhaengen()}.
 */
final class DiktatZiele
{
    /** Recorder-Ziele: die flachen Briefing-Felder der Ausgabeformen. Alles andere wird abgewiesen (Client setzt das Ziel). */
    public const ZIELE = ['fbBrief', 'skBrief', 'spBrief', 'offerBrief', 'fmtBrief'];

    public static function erlaubt(?string $ziel): bool
    {
        return $ziel !== null && in_array($ziel, self::ZIELE, true);
    }

    /**
     * Audio-Upload transkribieren.
     *
     * @return array{text: string, fehler: ?string} `fehler` gesetzt ⇒ nichts übernehmen (Meldung wörtlich anzeigen)
     */
    public static function transkribiere(object $audio): array
    {
        try {
            $text = trim(app(SttServiceContract::class)->transcribe(
                (string) file_get_contents($audio->getRealPath()),
                VoiceMime::aufgeloest($audio),
            ));
        } catch (\Throwable $e) {
            return ['text' => '', 'fehler' => 'Diktat fehlgeschlagen: ' . VoiceFehlerText::aus($e)['text']];
        }
        if ($text === '') {
            // Kann auch der Prompt-Echo-Riegel im STT sein (Aufnahme ohne Sprache).
            return ['text' => '', 'fehler' => 'Diktat war leer — nichts übernommen.'];
        }

        return ['text' => $text, 'fehler' => null];
    }

    /** Anhängen, nie ersetzen; leerer Nachtrag ändert nichts. */
    public static function anhaengen(string $alt, string $neu): string
    {
        $alt = trim($alt);
        $neu = trim($neu);
        if ($neu === '') {
            return $alt;
        }

        return $alt === '' ? $neu : $alt . ' ' . $neu;
    }
}
