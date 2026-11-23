#!/usr/bin/env python3
"""Teilt die Testdateien des Moduls auf N Shards auf und gibt die Dateien von Shard i aus.

Aufruf:  shard_dateien.py <tests-ordner> <i> <N> <präfix> [--phpunit <quelle.xml> <ziel.xml>] [--listen <ordner>]

Warum nicht `pest --shard`: Pest ermittelt die Testliste über `--list-tests` und erkennt dabei nur
Klassen im Namespace `Tests\\…`. Die Modultests liegen über den vendor-Symlink außerhalb des Hosts und
bekommen einen anderen Namespace (s. ci/host/tests/bootstrap.php) — die Liste bleibt leer, und jeder
Shard läuft still die GANZE Suite.

Aufteilung: alle *Test.php (wie die phpunit-<directory> der Suite FoodAlchemist), Gewicht = Zahl der
it(/test(-Aufrufe (mindestens 1), gierig auf den jeweils leichtesten Shard (größte zuerst). Deterministisch:
gleiche Dateien → gleiche Verteilung. Ausgabe: ein Pfad je Zeile, mit <präfix> vor dem Pfad relativ zum
tests-Ordner. Mit --phpunit: statt der Liste eine Kopie von <quelle.xml>, in der die Suite FoodAlchemist
genau diese Dateien als <file> führt (Paratest/--parallel arbeitet sauber mit einer Suite, nicht mit vielen Pfaden).
Mit --listen: zusätzlich <ordner>/dateien-<i>.txt (Dateien dieses Shards) und <ordner>/soll-<i>.txt (alle
gefundenen *Test.php, das Soll) — ci/zusammenfassung.py prüft daraus Vollständigkeit und Doppelläufe.
"""
import os
import re
import sys
from xml.sax.saxutils import escape

AUFRUF = re.compile(r"^\s*(?:it|test)\(", re.M)


def dateien(ordner: str) -> list:
    liste = []
    for wurzel, _, namen in os.walk(ordner):
        for n in namen:
            if n.endswith("Test.php"):
                pfad = os.path.join(wurzel, n)
                with open(pfad, encoding="utf-8", errors="replace") as f:
                    gewicht = max(1, len(AUFRUF.findall(f.read())))
                liste.append((os.path.relpath(pfad, ordner), gewicht))
    return liste


def verteile(liste: list, n: int) -> list:
    shards = [[] for _ in range(n)]
    last = [0] * n
    for pfad, gewicht in sorted(liste, key=lambda x: (-x[1], x[0])):
        k = min(range(n), key=lambda j: (last[j], j))
        shards[k].append(pfad)
        last[k] += gewicht
    return shards


def main() -> int:
    ordner, i, n = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
    praefix = sys.argv[4] if len(sys.argv) > 4 else ordner
    if not 1 <= i <= n:
        print(f"Shard {i} außerhalb 1..{n}", file=sys.stderr)
        return 2
    liste = dateien(ordner)
    shard = sorted(verteile(liste, n)[i - 1])
    print(f"Shard {i}/{n}: {len(shard)} von {len(liste)} Dateien", file=sys.stderr)
    pfade = [os.path.join(praefix, p) for p in shard]
    if "--listen" in sys.argv:
        ablage = sys.argv[sys.argv.index("--listen") + 1]
        os.makedirs(ablage, exist_ok=True)
        with open(os.path.join(ablage, f"dateien-{i}.txt"), "w", encoding="utf-8") as f:
            f.write("".join(p + "\n" for p in shard))
        with open(os.path.join(ablage, f"soll-{i}.txt"), "w", encoding="utf-8") as f:
            f.write("".join(p + "\n" for p in sorted(p for p, _ in liste)))
    if "--phpunit" in sys.argv:
        quelle, ziel = sys.argv[sys.argv.index("--phpunit") + 1:][:2]
        xml = open(quelle, encoding="utf-8").read()
        muster = re.compile(r'(<testsuite name="FoodAlchemist">).*?(</testsuite>)', re.S)
        if not muster.search(xml):
            print("Suite FoodAlchemist nicht in " + quelle, file=sys.stderr)
            return 2
        dateien_xml = "".join(f"\n            <file>{escape(p)}</file>" for p in pfade)
        xml = muster.sub(lambda m: m.group(1) + dateien_xml + "\n        " + m.group(2), xml)
        open(ziel, "w", encoding="utf-8").write(xml)
        return 0
    for pfad in pfade:
        print(pfad)
    return 0


if __name__ == "__main__":
    sys.exit(main())
