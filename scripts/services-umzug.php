<?php

/**
 * Dienste aus dem Root von src/Services in einen Fachordner verschieben (98 · 3.8 Ordnung). Rein mechanisch, ohne Umbenennung:
 * Kurz-Klassennamen bleiben gleich, also auch Kanten und Schichten im AbhaengigkeitsGraph.
 *
 * Aufruf (im Modul-Root, sauberer Arbeitsbaum):  php scripts/services-umzug.php <Ordner> <Klasse> [<Klasse> …]
 *
 * Schritte:
 *  1. git mv src/Services/<Klasse>.php → src/Services/<Ordner>/<Klasse>.php, namespace anpassen.
 *  2. In der verschobenen Datei: kurze Namen von Root-Diensten (bisher derselbe Namespace, ohne use) und relative Namen
 *     (`Foodbook\FoodbookBasis`) werden voll qualifiziert. Keine neuen use-Zeilen: Zeilenzahl bleibt (Größen-Basis).
 *  3. Im Root verbliebene Dienste, die eine verschobene Klasse mit kurzem Namen nutzten, schreiben `Ordner\Klasse`
 *     (relativ zum Root-Namespace, ebenfalls ohne neue Zeile).
 *  4. Voller Klassenname alt → neu in src, tests, resources, routes, config, database (einfacher und doppelter Backslash).
 *  5. Pfade `Services/<Klasse>.php` → `Services/<Ordner>/<Klasse>.php` in tests/ und der Spec90-Pflichtliste (Baselines, Wächter-Listen).
 *  6. Eintrag in tests/Support/ServicesUmzuege.php (Karte für Baselines und den Wächter ServicesOrdnungTest).
 *  7. SERVICES_ROOT_MAX in tests/Unit/ServicesOrdnungTest.php auf die neue Root-Zahl senken (Sperrklinke, Ziel < 50).
 *  8. Selbstprüfung mit scripts/fehlende-importe.php (inkl. Trait-use, extends/implements, Attribute); Exit 1 bei Fund.
 * Danach: php scripts/fehlende-importe.php, Klinke messen, UI-Zähler, Tests in der CI.
 *
 * Läuft auf jedem neuen main erneut — bei Konflikten mit paralleler Arbeit wird neu erzeugt statt gemergt.
 */
$modul = dirname(__DIR__);
[$_, $ordner] = $argv + [null, null];
$klassen = array_slice($argv, 2);
if ($ordner === null || $klassen === [] || ! preg_match('/^[A-Z]\w*$/', $ordner)) {
    fwrite(STDERR, "Aufruf: php scripts/services-umzug.php <Ordner> <Klasse> [<Klasse> …]\n");
    exit(2);
}
$basisNs = 'Platform\\FoodAlchemist\\Services';
$zielNs = $basisNs.'\\'.$ordner;

$root = array_map(fn ($p) => basename($p, '.php'), glob("$modul/src/Services/*.php"));
foreach ($klassen as $k) {
    if (! in_array($k, $root, true)) {
        fwrite(STDERR, "Nicht im Root: $k\n");
        exit(2);
    }
    if (is_file("$modul/src/Services/$ordner/$k.php")) {
        fwrite(STDERR, "Ziel existiert schon: $ordner/$k.php\n");
        exit(2);
    }
}
$bleibt = array_values(array_diff($root, $klassen));

/** Tokens zu Text. */
$text = fn (array $t) => implode('', array_map(fn ($x) => is_array($x) ? $x[1] : $x, $t));

/** Importierte Kurznamen (Alias) einer Datei. */
$importe = function (string $code): array {
    $aliase = [];
    preg_match_all('/^use (?!function |const )([^;]+);/m', $code, $u);
    foreach ($u[1] as $zeile) {
        $zeile = trim($zeile);
        $alias = preg_match('/\s+as\s+(\w+)$/i', $zeile, $a) ? $a[1] : substr(strrchr('\\'.$zeile, '\\'), 1);
        $aliase[$alias] = true;
    }

    return $aliase;
};

/**
 * Kurze Klassen-Verweise (T_STRING in Klassen-Position) auf $namen (Kurzname => egal) in Code ohne Kommentare/Strings; Index => Name.
 * Ausgeschlossen: Mitglieder nach -> / ?-> / ::, Deklarationen nach function/const/class/…, Funktionsaufrufe.
 */
$kurzVerweise = function (string $code, array $namen): array {
    $t = token_get_all($code);
    $sig = fn (int $i, int $r) => (function () use ($t, $i, $r) {
        for ($j = $i + $r; $j >= 0 && $j < count($t); $j += $r) {
            if (! is_array($t[$j]) || ! in_array($t[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $t[$j];
            }
        }

        return null;
    })();
    $gefunden = [];
    $inUse = false;
    $tiefe = 0;
    foreach ($t as $i => $x) {
        // Nur Importe auf oberster Ebene überspringen; Trait-use im Klassenrumpf nennt Klassen (98 · 3.8, CI-Fund PlattformAdminPruefung)
        if (is_array($x) && $x[0] === T_USE && $tiefe === 0) {
            $inUse = true;
        }
        if ($x === ';' || $x === '{') {
            $inUse = false;
        }
        if ($x === '{' || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $tiefe++;
        } elseif ($x === '}') {
            $tiefe--;
        }
        if (! is_array($x) || $x[0] !== T_STRING || ! isset($namen[$x[1]]) || $inUse) {
            continue;
        }
        $vor = $sig($i, -1);
        $nach = $sig($i, 1);
        $vorId = is_array($vor) ? $vor[0] : $vor;
        if (in_array($vorId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_CASE, T_GOTO], true)) {
            continue;
        }
        if ($nach === '(' && $vorId !== T_NEW) {
            continue;   // Funktionsaufruf
        }
        $gefunden[$i] = $x[1];
    }

    return $gefunden;
};

/** Kurze Verweise ersetzen (kein neues use: keine Zeile mehr, Größen-Basis bleibt). $ersatz: Kurzname → neuer Text. */
$ersetzeKurz = function (string $code, array $ersatz) use ($kurzVerweise, $importe): string {
    $ohneImport = array_diff_key($ersatz, $importe($code));
    if ($ohneImport === []) {
        return $code;
    }
    $treffer = $kurzVerweise($code, $ohneImport);
    if ($treffer === []) {
        return $code;
    }
    $t = token_get_all($code);
    foreach ($treffer as $i => $name) {
        $t[$i][1] = $ohneImport[$name];
    }

    return implode('', array_map(fn ($x) => is_array($x) ? $x[1] : $x, $t));
};

$geaendert = [];

// 1 + 2: verschieben, namespace, use für Root-Nachbarn, relative Namen voll qualifizieren
@mkdir("$modul/src/Services/$ordner");
foreach ($klassen as $k) {
    $alt = "src/Services/$k.php";
    $neu = "src/Services/$ordner/$k.php";
    exec('git -C '.escapeshellarg($modul).' mv '.escapeshellarg($alt).' '.escapeshellarg($neu), $o, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "git mv fehlgeschlagen: $alt\n");
        exit(1);
    }
    $code = (string) file_get_contents("$modul/$neu");
    $code = preg_replace('/^namespace '.preg_quote($basisNs, '/').';/m', "namespace $zielNs;", $code, 1, $n);
    if ($n !== 1) {
        fwrite(STDERR, "namespace nicht gefunden: $neu\n");
        exit(1);
    }
    // relative Namen (T_NAME_QUALIFIED außerhalb von use/namespace), deren erstes Segment kein Import ist → voll qualifizieren
    $aliase = $importe($code);
    $t = token_get_all($code);
    $inUse = false;
    $tiefe = 0;
    foreach ($t as $i => $x) {
        if (is_array($x) && in_array($x[0], [T_USE, T_NAMESPACE], true) && $tiefe === 0) {   // Trait-use im Rumpf zählt als Verweis
            $inUse = true;
        }
        if ($x === ';' || $x === '{') {
            $inUse = false;
        }
        if ($x === '{' || (is_array($x) && in_array($x[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $tiefe++;
        } elseif ($x === '}') {
            $tiefe--;
        }
        if (! $inUse && is_array($x) && $x[0] === T_NAME_QUALIFIED && ! isset($aliase[strstr($x[1], '\\', true)])) {
            $t[$i][1] = '\\'.$basisNs.'\\'.$x[1];
        }
    }
    $code = $text($t);
    // kurze Verweise auf Root-Dienste, die nicht mitwandern → voll qualifiziert (wandern sie später, zieht Schritt 4 den Namen nach)
    $code = $ersetzeKurz($code, array_map(fn ($n) => '\\'.$basisNs.'\\'.$n, array_combine($bleibt, $bleibt)));
    file_put_contents("$modul/$neu", $code);
    $geaendert[$neu] = true;
}

// 3: Root-Nachbarn mit kurzem Verweis auf eine verschobene Klasse
foreach ($bleibt as $b) {
    $pfad = "src/Services/$b.php";
    $code = (string) file_get_contents("$modul/$pfad");
    // relativ zum Root-Namespace: Ordner\Klasse (keine neue use-Zeile; wandert die Datei später, macht Schritt 2 daraus den vollen Namen)
    $neuCode = $ersetzeKurz($code, array_map(fn ($n) => $ordner.'\\'.$n, array_combine($klassen, $klassen)));
    if ($neuCode !== $code) {
        file_put_contents("$modul/$pfad", $neuCode);
        $geaendert[$pfad] = true;
    }
}

// 4 + 5: volle Namen und Pfade
$fqnMuster = [];
foreach ($klassen as $k) {
    foreach (['\\', '\\\\'] as $bs) {
        $altFqn = str_replace('\\', $bs, "$basisNs\\$k");
        $neuFqn = str_replace('\\', $bs, "$zielNs\\$k");
        $fqnMuster['/'.preg_quote($altFqn, '/').'(?![\\\\\w])/'] = $neuFqn;
    }
}
$pfadMuster = [];
foreach ($klassen as $k) {
    $pfadMuster['#(?<![\w/])((?:src/)?Services/)'.preg_quote($k, '#').'\.php#'] = '${1}'.$ordner.'/'.$k.'.php';
}
$dateien = [];
foreach (['src', 'tests', 'resources', 'routes', 'config', 'database'] as $o) {
    if (is_dir("$modul/$o")) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$modul/$o", FilesystemIterator::SKIP_DOTS)) as $d) {
            if (in_array($d->getExtension(), ['php', 'json'], true)) {
                $dateien[] = substr($d->getPathname(), strlen($modul) + 1);
            }
        }
    }
}
$dateien[] = 'docs/PLANUNG/90_KI_Dualisieren_Pflichtliste.md';
foreach ($dateien as $pfad) {
    if (str_ends_with($pfad, 'tests/Support/ServicesUmzuege.php') || ! is_file("$modul/$pfad")) {
        continue;
    }
    $code = (string) file_get_contents("$modul/$pfad");
    $neuCode = preg_replace(array_keys($fqnMuster), array_values($fqnMuster), $code);
    if (str_starts_with($pfad, 'tests/') || str_starts_with($pfad, 'docs/')) {
        $neuCode = preg_replace(array_keys($pfadMuster), array_values($pfadMuster), $neuCode);
    }
    if ($neuCode !== $code) {
        file_put_contents("$modul/$pfad", $neuCode);
        $geaendert[$pfad] = true;
    }
}

// 6: Karte
$kartePfad = "$modul/tests/Support/ServicesUmzuege.php";
$karte = (string) file_get_contents($kartePfad);
preg_match('/public const KARTE = \[\n(.*?)    \];/s', $karte, $m);
$eintraege = array_filter(explode("\n", $m[1]), fn ($z) => trim($z) !== '');
foreach ($klassen as $k) {
    $eintraege[] = "        'Services/$k.php' => 'Services/$ordner/$k.php',";
}
$eintraege = array_values(array_unique($eintraege));
sort($eintraege);
$karte = str_replace($m[0], "public const KARTE = [\n".implode("\n", $eintraege)."\n    ];", $karte);
file_put_contents($kartePfad, $karte);
$geaendert['tests/Support/ServicesUmzuege.php'] = true;

// 7: Sperrklinke „Root schrumpft nur“ auf den neuen Stand
$testPfad = "$modul/tests/Unit/ServicesOrdnungTest.php";
$rootJetzt = count(glob("$modul/src/Services/*.php"));
$test = (string) file_get_contents($testPfad);
$test = preg_replace('/^const SERVICES_ROOT_MAX = \d+;/m', "const SERVICES_ROOT_MAX = $rootJetzt;", $test, 1);
file_put_contents($testPfad, $test);
$geaendert['tests/Unit/ServicesOrdnungTest.php'] = true;

ksort($geaendert);
echo count($klassen)." Klasse(n) nach Services/$ordner, ".count($geaendert)." Datei(en) geändert, Root jetzt $rootJetzt.\n";

// 8: Selbstprüfung — ein Kurzname ohne Import im falschen Namespace (z. B. Trait-use im Rumpf) bricht erst zur Laufzeit
passthru('php '.escapeshellarg("$modul/scripts/fehlende-importe.php"), $rc);
if ($rc !== 0) {
    fwrite(STDERR, "Fehlende Imports nach dem Umzug — Skript prüfen, nichts committen.\n");
    exit(1);
}
