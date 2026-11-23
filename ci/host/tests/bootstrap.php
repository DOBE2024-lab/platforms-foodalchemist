<?php

/**
 * CI-Host-Test-Bootstrap — Workaround für den Pest-Namespace-Crash.
 *
 * Pest leitet den Test-Klassen-Namespace aus dem absoluten realpath ab und strippt
 * dabei nur TestSuite::rootPath (= Host-Ordner). Die Modul-Tests liegen über den
 * vendor-Symlink auf den Modul-Checkout außerhalb des Hosts. Beginnt ein Segment
 * dieses Pfads mit einer Ziffer, entsteht ein ungültiger PHP-Namespace →
 * „Unable to create test case".
 *
 * Fix: rootPath auf den gemeinsamen Elternordner von Host und Modul-Checkout heben.
 * Damit bleiben nur die Segmente unterhalb davon übrig — alle gültige Identifier.
 */
require __DIR__.'/../vendor/autoload.php';

if (class_exists(\Pest\TestSuite::class)) {
    try {
        $ts = \Pest\TestSuite::getInstance();
        // Host-rootPath → gemeinsamer Elternordner
        $ts->rootPath = dirname($ts->rootPath);
    } catch (\Throwable $e) {
        // still: läuft ggf. ohne aktive Pest-Instanz (reines PHPUnit) — dann kein Eingriff nötig
    }
}
