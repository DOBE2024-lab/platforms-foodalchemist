#!/usr/bin/env python3
"""Fasst die Pest-Shards der Suite zusammen (GitHub-Step-Summary, Markdown).

Aufruf:  zusammenfassung.py <ordner> <needs.test.result> [--pruefen]

Quellen je Shard (Artefakt shard-<n>):
  pest-<n>.log   Pest-Ausgabe. Die Zeile „Tests: …" liefert die Zahlen wie in Pauls lokalem Bericht
                 (passed, failed, skipped, risky, …) — JUnit kennt kein „risky".
  junit-<n>.xml  Für die Rot-Liste (Datei, Test, erste Zeile der Meldung).
  dateien-<n>.txt / soll-<n>.txt  Aufteilung (ci/shard_dateien.py). Geprüft: die Vereinigung aller Shard-
                 Listen ist genau das Soll, jede Datei genau einmal, und alle Shards sahen dasselbe Soll.
Umgebung: SHARDS, REF, MODUL_SHA, GESCHWISTER (JSON repo → sha).

Ohne --pruefen: Markdown auf stdout. Mit --pruefen: Exit 1, wenn etwas rot ist oder ein Shard
kein Ergebnis geliefert hat (Aufbaufehler, Abbruch, Zeitlimit).
"""
import glob
import json
import os
import re
import sys
import xml.etree.ElementTree as ET

SHARDS = int(os.environ.get("SHARDS", "12"))
ANSI = re.compile(r"\x1b\[[0-9;]*[A-Za-z]")
ARTEN = ["passed", "failed", "skipped", "risky", "incomplete", "todo", "warnings", "deprecated", "notices"]


def erste_zeile(text: str) -> str:
    for zeile in (text or "").splitlines():
        zeile = zeile.strip()
        if zeile:
            return zeile[:300]
    return ""


def kurzpfad(pfad: str) -> str:
    m = re.search(r"(tests/.*)$", pfad or "")
    return m.group(1) if m else (pfad or "?")


def nr_aus(datei: str) -> int:
    return int(re.search(r"-(\d+)\.(?:xml|log)$", datei).group(1))


def lies_log(datei: str):
    """Letzte „Tests:"-Zeile und „Duration:" der Pest-Ausgabe."""
    text = ANSI.sub("", open(datei, encoding="utf-8", errors="replace").read())
    zeilen = [z for z in text.splitlines() if re.match(r"\s*Tests:\s", z)]
    if not zeilen:
        return None
    zahl = {a: 0 for a in ARTEN}
    for n, art in re.findall(r"(\d+)\s+([a-z]+)", zeilen[-1].split("(")[0]):
        art = {"warning": "warnings", "deprecation": "deprecated", "notice": "notices"}.get(art, art)
        if art in zahl:
            zahl[art] += int(n)
    m = re.search(r"\((\d+) assertions?\)", zeilen[-1])
    zahl["assertions"] = int(m.group(1)) if m else 0
    zahl["tests"] = sum(zahl[a] for a in ("passed", "failed", "skipped", "risky", "incomplete", "todo"))
    d = re.findall(r"Duration:\s*([\d.]+)s", text)
    zahl["dauer"] = float(d[-1]) if d else 0.0
    return zahl


def lies_rote(ordner: str) -> list:
    rote = []
    for datei in glob.glob(os.path.join(ordner, "**", "junit-*.xml"), recursive=True):
        try:
            wurzel = ET.parse(datei).getroot()
        except ET.ParseError:
            continue
        for tc in wurzel.iter("testcase"):
            fehler = tc.find("failure")
            if fehler is None:
                fehler = tc.find("error")
            if fehler is not None:
                rote.append({
                    "shard": nr_aus(datei),
                    "datei": kurzpfad(tc.get("file") or tc.get("classname") or ""),
                    "test": tc.get("name") or "?",
                    "grund": erste_zeile(fehler.get("message") or fehler.text or ""),
                })
    return rote


def pruefe_aufteilung(ordner: str) -> list:
    """Fehlerliste (leer = ok): fehlende, doppelte oder fremde Dateien, abweichendes Soll."""
    def lies(muster):
        out = {}
        for datei in glob.glob(os.path.join(ordner, "**", muster), recursive=True):
            nr = int(re.search(r"-(\d+)\.txt$", datei).group(1))
            out[nr] = [z.strip() for z in open(datei, encoding="utf-8") if z.strip()]
        return out
    listen, solls = lies("dateien-*.txt"), lies("soll-*.txt")
    fehler = []
    fehlend_listen = [i for i in range(1, SHARDS + 1) if i not in listen]
    if fehlend_listen:
        fehler.append(f"keine Dateiliste von Shard {fehlend_listen}")
    if not solls:
        return fehler + ["kein Soll"]
    soll_werte = {tuple(v) for v in solls.values()}
    if len(soll_werte) > 1:
        fehler.append("Shards sahen unterschiedliche Soll-Listen")
    soll = sorted(set(next(iter(solls.values()))))
    alle = [p for v in listen.values() for p in v]
    zaehl = {}
    for p in alle:
        zaehl[p] = zaehl.get(p, 0) + 1
    doppelt = sorted(p for p, n in zaehl.items() if n > 1)
    fehlt = sorted(set(soll) - set(zaehl)) if not fehlend_listen else []
    fremd = sorted(set(zaehl) - set(soll))
    if doppelt:
        fehler.append(f"{len(doppelt)} Datei(en) in mehreren Shards: {', '.join(doppelt[:5])}")
    if fehlt:
        fehler.append(f"{len(fehlt)} Datei(en) in keinem Shard: {', '.join(fehlt[:5])}")
    if fremd:
        fehler.append(f"{len(fremd)} Datei(en) nicht im Soll: {', '.join(fremd[:5])}")
    if not fehler:
        fehler_frei = f"{len(alle)} Dateien = Soll {len(soll)}, jede genau einmal"
        print(fehler_frei, file=sys.stderr)
    return fehler


def lies_vorlage(ordner: str):
    """Zeilen „TestDbVorlage pid=… geladen=… gebaut=… alt_nicht_leer=… aus=… [alt_in=K:n,…]“ aus vorlage-<n>.log."""
    summe = {"geladen": 0, "gebaut": 0, "alt_nicht_leer": 0, "aus": 0}
    alt_in, prozesse = {}, 0
    for datei in glob.glob(os.path.join(ordner, "**", "vorlage-*.log"), recursive=True):
        for zeile in open(datei, encoding="utf-8", errors="replace"):
            if not zeile.startswith("TestDbVorlage "):
                continue
            prozesse += 1
            for k in summe:
                m = re.search(rf"\b{k}=(\d+)", zeile)
                summe[k] += int(m.group(1)) if m else 0
            m = re.search(r"alt_in=(\S+)", zeile)
            for teil in (m.group(1).split(",") if m else []):
                name, _, n = teil.rpartition(":")
                kurz = name.rsplit("\\", 1)[-1]
                alt_in[kurz] = alt_in.get(kurz, 0) + int(n or 0)
    return {"summe": summe, "alt_in": alt_in, "prozesse": prozesse} if prozesse else None


def main() -> int:
    ordner, ergebnis = sys.argv[1], sys.argv[2]
    pruefen = "--pruefen" in sys.argv
    shards = {}
    for datei in glob.glob(os.path.join(ordner, "**", "pest-*.log"), recursive=True):
        z = lies_log(datei)
        if z is not None:
            shards[nr_aus(datei)] = z
    rote = lies_rote(ordner)
    fehlend = [i for i in range(1, SHARDS + 1) if i not in shards]
    summe = {k: sum(s[k] for s in shards.values()) for k in [*ARTEN, "assertions", "tests"]}
    rot = summe["failed"] > 0 or bool(rote)
    aufteilung = pruefe_aufteilung(ordner)

    if pruefen:
        if rot or fehlend or aufteilung or ergebnis not in ("success", "failure"):
            print(f"ROT: {summe['failed']} failed, Shards ohne Ergebnis: {fehlend or '-'}, Matrix: {ergebnis}, "
                  f"Aufteilung: {'; '.join(aufteilung) or 'ok'}")
            return 1
        print("GRÜN")
        return 0

    urteil = "🟢 grün" if not rot and not fehlend and not aufteilung else "🔴 rot"
    print(f"## FoodAlchemist-Suite: {urteil}\n")
    if aufteilung:
        print("**Aufteilung fehlerhaft:** " + "; ".join(aufteilung) + "\n")
    else:
        print("Aufteilung geprüft: jede Testdatei genau einmal.\n")
    print(f"Ref `{os.environ.get('REF', '?')}` → `{os.environ.get('MODUL_SHA', '?')}`\n")
    print(f"**{summe['tests']} Tests: {summe['passed']} passed, {summe['failed']} failed, "
          f"{summe['skipped']} skipped, {summe['risky']} risky** "
          f"({summe['assertions']} assertions; incomplete {summe['incomplete']}, todo {summe['todo']}, "
          f"warnings {summe['warnings']}, deprecated {summe['deprecated']})\n")
    dauern = [s["dauer"] for s in shards.values()]
    if dauern:
        print(f"Shards {len(shards)}/{SHARDS}, Pest-Dauer je Shard max {max(dauern) / 60:.1f} min, "
              f"Summe {sum(dauern) / 60:.1f} min.\n")
    print("| Shard | Tests | passed | failed | skipped | risky | Dauer (min) |")
    print("|---:|---:|---:|---:|---:|---:|---:|")
    for i in range(1, SHARDS + 1):
        s = shards.get(i)
        if s is None:
            print(f"| {i} | **kein Ergebnis** | – | – | – | – | – |")
        else:
            print(f"| {i} | {s['tests']} | {s['passed']} | {s['failed']} | {s['skipped']} | {s['risky']} | {s['dauer'] / 60:.1f} |")
    if fehlend:
        print("\nShards ohne Ergebnis: Aufbau-, Speicher- oder Zeitfehler — Log des Shard-Jobs ansehen.")
    if rote:
        print("\n### Rote Tests\n")
        print("| Shard | Datei | Test | Grund |")
        print("|---:|---|---|---|")
        zelle = lambda t: t.replace("|", "\\|")
        for r in sorted(rote, key=lambda r: (r["datei"], r["test"])):
            print(f"| {r['shard']} | `{zelle(r['datei'])}` | {zelle(r['test'])} | {zelle(r['grund'])} |")
    vorlage = lies_vorlage(ordner)
    if vorlage:
        z = vorlage["summe"]
        print("\n### Test-DB-Vorlage\n")
        print(f"geladen {z['geladen']}, gebaut {z['gebaut']}, alter Weg (DB nicht leer) {z['alt_nicht_leer']}, "
              f"abgeschaltet {z['aus']} — {vorlage['prozesse']} Prozesse.")
        if vorlage["alt_in"]:
            print("\nAlter Weg in: " + ", ".join(f"`{k}` ({n})" for k, n in sorted(vorlage["alt_in"].items(), key=lambda x: -x[1])[:30]))
    try:
        geschwister = json.loads(os.environ.get("GESCHWISTER") or "{}")
    except json.JSONDecodeError:
        geschwister = {}
    if geschwister:
        print("\n### Stände der Geschwister-Module (main des Originals)\n")
        print("| Repo | SHA |")
        print("|---|---|")
        for repo, sha in sorted(geschwister.items()):
            print(f"| {repo} | `{sha}` |")
    return 0


if __name__ == "__main__":
    sys.exit(main())
