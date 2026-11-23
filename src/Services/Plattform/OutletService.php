<?php

namespace Platform\FoodAlchemist\Services\Plattform;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * 98 · 1.3c · Kalkulationsprofile (Outlets) anlegen — EIN Weg für Einstellungen (Settings\Betriebe) und MCP
 * (outlets.POST). Vorher legten beide getrennt an; das Tool ohne Sortierung.
 * 98 · 2.3a · auch Ändern, Logo und aktiv/inaktiv — nur team-EIGENE Outlets (kein Erben, kein Master-Schreiben).
 */
class OutletService
{
    /** @throws \RuntimeException bei leerem oder schon vergebenem Namen (Unique ist team_id + name). */
    public function anlegen(Team $team, string $name): FoodAlchemistOutlet
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('name darf nicht leer sein.');
        }
        if (FoodAlchemistOutlet::where('team_id', $team->id)->where('name', $name)->exists()) {
            throw new \RuntimeException('Es gibt bereits ein Kalkulationsprofil mit diesem Namen.');
        }

        return FoodAlchemistOutlet::create([
            'team_id' => $team->id,
            'name' => $name,
            'sort_order' => (int) (FoodAlchemistOutlet::where('team_id', $team->id)->max('sort_order') ?? 0) + 10,
        ]);
    }

    /** Nur team-EIGENE Outlets sind änderbar (Vokabular gehört dem Team, das es pflegt). */
    public function eigenes(Team $team, int $id): FoodAlchemistOutlet
    {
        return FoodAlchemistOutlet::where('team_id', $team->id)->find($id)
            ?? throw new NichtGefundenException('Dieses Kalkulationsprofil gibt es nicht oder es gehört nicht deinem Team.');
    }

    /**
     * Name, Farbe, Reihenfolge, Präsentations-Vorlage. Kosten-Overrides je Betrieb wohnen im OutletSettingsService.
     *
     * @param  array{name?:string,color?:string,sort_order?:int|string,vorlage?:string}  $eingabe
     */
    public function aktualisieren(Team $team, int $id, array $eingabe): FoodAlchemistOutlet
    {
        $o = $this->eigenes($team, $id);
        $name = trim((string) ($eingabe['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('name darf nicht leer sein.');
        }
        if (FoodAlchemistOutlet::where('team_id', $team->id)->where('name', $name)->where('id', '!=', $o->id)->exists()) {
            throw new \InvalidArgumentException('Es gibt bereits ein Kalkulationsprofil mit diesem Namen.');
        }

        $farbe = trim((string) ($eingabe['color'] ?? ''));
        $o->update([
            'name' => $name,
            // Nur echtes Hex durchlassen — die Farbe landet direkt im Style-Attribut.
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $farbe) === 1 ? $farbe : null,
            'sort_order' => max(0, (int) ($eingabe['sort_order'] ?? 100)),
            // Slice F: Präsentations-Vorlage je Betrieb (leer = Dokument-Vorlage beim Betriebs-Link).
            'presentation_design' => ($v = trim((string) ($eingabe['vorlage'] ?? ''))) !== '' ? $v : null,
        ]);

        return $o;
    }

    /** Betriebs-Logo ersetzen (altes weg) — beim Betriebs-Link ersetzt es das Dokument-Logo. Die Datei ist schon geprüft. */
    public function logoErsetzen(Team $team, int $id, mixed $datei): FoodAlchemistOutlet
    {
        $o = $this->eigenes($team, $id);
        $media = app(FoodAlchemistMediaService::class);
        $media->loescheWennUnbenutzt($o->logo_context_file_id, (string) $o->logo_path, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($o, 'logo_path')]);
        $res = $media->storeImage($datei, $team, 'foodalchemist.outlet', $o->id, "foodalchemist/outlet-logo/{$o->id}");
        $o->update(['logo_path' => $res['path'], 'logo_context_file_id' => $res['context_file_id']]);

        return $o;
    }

    /** Betriebs-Logo entfernen → beim Betriebs-Link greift wieder das Dokument-Logo. */
    public function logoEntfernen(Team $team, int $id): FoodAlchemistOutlet
    {
        $o = $this->eigenes($team, $id);
        app(FoodAlchemistMediaService::class)->loescheWennUnbenutzt($o->logo_context_file_id, (string) $o->logo_path, $team, [\Platform\FoodAlchemist\Support\BildVerweise::eigen($o, 'logo_path')]);
        $o->update(['logo_path' => null, 'logo_context_file_id' => null]);

        return $o;
    }

    /** Aktiv/inaktiv statt löschen — an einem Outlet hängen Ausgaben und Kapitel. */
    public function aktivUmschalten(Team $team, int $id): FoodAlchemistOutlet
    {
        $o = $this->eigenes($team, $id);
        $o->update(['is_inactive' => ! $o->is_inactive]);

        return $o;
    }
}
