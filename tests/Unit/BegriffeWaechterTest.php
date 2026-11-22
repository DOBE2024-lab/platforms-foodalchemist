<?php

/**
 * Plan 98, 2.4f (U8), Entscheidung Dominique 22.11.2026: In sichtbaren Texten heißt es „Konzept/Konzepte“ und
 * „Konzepter“, nie „Concept/Concepts/Concepter“. Der Wächter sucht das Wort in Blade und src — ohne Kommentare und
 * ohne Bezeichner (Klassen, Namespaces, Methoden, Routen, data-Attribute sind klein oder Teil eines längeren Worts).
 *
 * Nicht geprüft: src/Tools (MCP-Beschreibungen für Agenten), src/Console (Betrieb), Log-Zeilen.
 * Benannte Ausnahmen unten — jede mit Grund. Neue Ausnahme nur mit Grund.
 */
const BEGRIFFE_AUSNAHMEN = [
    // [Datei, Teilstring der Zeile, Grund]
    ['src/Livewire/Concepter/Browser.php', "'Neues Concept', 'is_template'", 'entfällt mit 2.4c (Anlege-Dialog)'],
    ['src/Livewire/Concepts/Index.php', "'Neues Concept', 'is_template'", 'entfällt mit 2.4c (Anlege-Dialog)'],
    ['src/Support/FaBereiche.php', "'Concepter' => 'concepter', 'Concepts' => 'concepter'", 'Namespace-Segmente als Schlüssel'],
    ['src/Services/PlanningSessionService.php', "'Freies Concept'", 'alter Platzhaltertitel bestehender Sitzungen wird weiter erkannt'],
    ['src/Services/VoiceCommandService.php', '', 'KI-Prompt, kein sichtbarer Text'],
    ['src/Support/KiUmleitung.php', '', 'Beschreibung für Agenten'],
    ['src/Services/VorgangsRegisterService.php', '', 'Vorgänge für Agenten (foodalchemist.ablauf.GET)'],
    ['src/Services/PaketUmzug/PaketUmzugService.php', '', 'Umzugsprotokoll für den Betrieb'],
    ['resources/views/livewire/ui-katalog.blade.php', "['Konzept, Konzepte', 'Concept, Concepts'", 'Begriffsliste nennt das alte Wort'],
    ['resources/views/livewire/ui-katalog.blade.php', "['Konzepter', 'Concepter'", 'Begriffsliste nennt das alte Wort'],
];

function begriffeTreffer(): array
{
    $wurzel = dirname(__DIR__, 2);
    $wort = '/(?<![\w\\\\$\/.])(?<!->)(?<!::)(Concepter|Concepts|Concept)(?![\w\\\\(]|::)/';
    $leer = fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]);
    $treffer = [];
    $dateien = [];
    foreach (['resources/views', 'src'] as $ordner) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$wurzel/$ordner", FilesystemIterator::SKIP_DOTS)) as $f) {
            if (str_ends_with($f->getFilename(), '.php')) {
                $dateien[] = substr($f->getPathname(), strlen($wurzel) + 1);
            }
        }
    }
    $dateien[] = 'config/foodalchemist.php';
    foreach ($dateien as $rel) {
        if (str_starts_with($rel, 'src/Tools/') || str_starts_with($rel, 'src/Console/')) {
            continue;
        }
        $roh = file_get_contents("$wurzel/$rel");
        $s = $roh;
        if (str_ends_with($rel, '.blade.php')) {
            $s = preg_replace_callback('/\{\{--.*?--\}\}/s', $leer, $s);
        }
        $s = preg_replace_callback('/\/\*.*?\*\//s', $leer, $s);
        $s = preg_replace_callback('/(^|[ \t])\/\/[^\n]*/m', fn ($m) => $m[1] . str_repeat(' ', strlen($m[0]) - strlen($m[1])), $s);
        $zeilenRoh = explode("\n", $roh);
        foreach (explode("\n", $s) as $i => $zeile) {
            if (! preg_match($wort, $zeile)) {
                continue;
            }
            $original = $zeilenRoh[$i];
            if (str_contains($original, 'Log::')) {
                continue;
            }
            // config: nur Nav-Labels und der Transkriptions-Hinweis sind sichtbar; der Rest sind KI-Prompts
            if ($rel === 'config/foodalchemist.php' && ! str_contains($original, "'label' =>") && ! str_contains($original, 'Fachbegriffe') && ! str_contains($original, 'Foodbook, Konzepter')) {
                continue;
            }
            $ausnahme = false;
            foreach (BEGRIFFE_AUSNAHMEN as [$datei, $teil]) {
                if ($datei === $rel && ($teil === '' || str_contains($original, $teil))) {
                    $ausnahme = true;
                    break;
                }
            }
            if (! $ausnahme) {
                $treffer[] = $rel . ':' . ($i + 1) . ': ' . trim(mb_substr($original, 0, 140));
            }
        }
    }

    return $treffer;
}

it('sichtbare Texte sagen „Konzept/Konzepte/Konzepter“, nicht „Concept/Concepts/Concepter“', function () {
    expect(begriffeTreffer())->toBe([]);
});

it('Wächter-Regex erkennt Text und lässt Bezeichner in Ruhe', function () {
    $wort = '/(?<![\w\\\\$\/.])(?<!->)(?<!::)(Concepter|Concepts|Concept)(?![\w\\\\(]|::)/';

    foreach (['Neues Concept', '>Concepts<', 'title="Concepter"', 'Lunch-Concept'] as $text) {
        expect(preg_match($wort, $text))->toBe(1, $text);
    }
    foreach (['FoodAlchemistConcept', 'Livewire\\Concepter\\Browser', 'Concept::class', '$this->Concept', 'ConceptService', 'foodalchemist.concepter.index', 'data-concept'] as $code) {
        expect(preg_match($wort, $code))->toBe(0, $code);
    }
});

it('Begriffsliste steht im Designsystem', function () {
    $katalog = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/ui-katalog.blade.php');

    expect($katalog)->toContain('data-katalog-begriffe=""')->toContain("['Konzepter', 'Concepter'")->toContain("['Im Editor öffnen'")->toContain("['Ändern', 'Bearbeiten'");
});
