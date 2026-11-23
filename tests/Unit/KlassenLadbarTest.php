<?php

/**
 * 98 · 3.8: Jede Klasse, jedes Trait, Interface und Enum unter src lässt sich über den Autoloader laden. Ein fehlendes Trait,
 * ein falscher Elternname oder ein verlorenes Interface ist in PHP ein Fatal beim Laden der Datei — das fiel bisher erst bei
 * package:discover in der CI-Installation auf (CI-Fund 3.8 PR1: Trait-use PlattformAdminPruefung nach dem Umzug).
 *
 * Läuft in einem eigenen PHP-Prozess, weil ein Fatal nicht fangbar ist und sonst den Test-Prozess beendet. Der Kind-Prozess
 * nutzt den Composer-Autoloader des Laufs und zwingt PSR-4 für das Modul auf dieses src (kein Rückfall auf andere Checkouts).
 */
it('jede Klasse unter src ist ladbar (kein fehlendes Trait, kein falscher Elternname)', function () {
    $src = dirname(__DIR__, 2).'/src';
    $autoload = dirname((new ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2).'/autoload.php';

    $kind = <<<'PHP'
        [$autoload, $src] = [$argv[1], $argv[2]];
        $loader = require $autoload;
        $loader->setPsr4('Platform\\FoodAlchemist\\', [$src]);
        $n = 0;
        $fehler = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $d) {
            if ($d->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($d->getPathname());
            if (! preg_match('/^namespace ([^;]+);/m', $code, $ns)
                || ! preg_match('/^(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', $code, $k)) {
                continue;
            }
            $fqn = $ns[1].'\\'.$k[1];
            try {
                if (! (class_exists($fqn) || trait_exists($fqn) || interface_exists($fqn) || enum_exists($fqn))) {
                    $fehler[] = "nicht ladbar: $fqn";
                }
                $n++;
            } catch (Throwable $e) {
                $fehler[] = "$fqn: ".$e->getMessage();
            }
        }
        echo json_encode(['geladen' => $n, 'fehler' => $fehler]);
        PHP;

    $befehl = escapeshellarg(PHP_BINARY).' -d memory_limit=1G -r '.escapeshellarg($kind).' '.escapeshellarg($autoload).' '.escapeshellarg($src).' 2>&1';
    exec($befehl, $ausgabe, $rc);
    $text = implode("\n", $ausgabe);
    $json = json_decode((string) end($ausgabe), true);

    expect($rc)->toBe(0, "Kind-Prozess brach ab (Fatal beim Laden?):\n".$text)
        ->and($json)->toBeArray()
        ->and($json['fehler'] ?? null)->toBe([])
        ->and($json['geladen'] ?? 0)->toBeGreaterThan(1500);
});
