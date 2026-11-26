<?php

/*
 * Code-Qualität 3.7 — Regel-Test: Warengruppen-Rollen (Getränk '15', Non-Food '16') und die Knabbereien-Fachregel
 * ('09.6') stehen nicht mehr als Literal im Code. Rolle prüfen über Support\WgRolle (istGetraenk/istNonFood/
 * istKuehl/istFertig, codes() für Abfragen). Ausnahmen mit Höchstzahl je Datei:
 *  · WgRolle: die Standard-Belegung selbst.
 *  · Herstellkosten: `$marge = '15'` (Prozent-Vorbelegung, keine Warengruppe).
 *  · VocabularyService::PARAGRAF3_CODES: die vollständige §3-Code-Liste, keine Rolle.
 *  · ZukaufBasisrezeptService: die benannte Fachregel SUB_KNABBEREIEN = '09.6'.
 * Migrationen sind ausgenommen (eingefrorener Stand).
 */
it('Sperre: keine Warengruppen-Rollen als Code-Literal (CQ 3.7)', function () {
    $basis = dirname(__DIR__, 2);
    $erlaubt = [
        'src/Support/WgRolle.php' => 2,
        'src/Livewire/Settings/Herstellkosten.php' => 1,
        'src/Services/GpStamm/VocabularyService.php' => 1,
        'src/Services/ZukaufBasisrezeptService.php' => 1,
    ];
    $funde = [];
    foreach (['src', 'resources'] as $ordner) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basis.'/'.$ordner, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $datei) {
            if (! str_ends_with($datei->getFilename(), '.php')) {
                continue;
            }
            $code = (string) file_get_contents($datei->getPathname());
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);   // Doku-Kommentare zählen nicht
            $n = preg_match_all("/'(?:1[56]|09\\.6)'/", $code);
            $rel = substr($datei->getPathname(), strlen($basis) + 1);
            if ($n > ($erlaubt[$rel] ?? 0)) {
                $funde[] = "{$rel} ({$n})";
            }
        }
    }
    expect($funde)->toBe([]);
});
