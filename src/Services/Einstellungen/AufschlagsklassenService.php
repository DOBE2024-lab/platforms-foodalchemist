<?php

namespace Platform\FoodAlchemist\Services\Einstellungen;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Exceptions\NichtGefundenException;
use Platform\FoodAlchemist\Exceptions\NochVerwendetException;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Services\Preis\CatalogPricingService;
use Platform\FoodAlchemist\Services\PricingCascadeService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\BasisKatalog;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 98 · 2.3a — Preisklassen (Einstellungen › Aufschlagsklassen) headless anlegen, ändern, (de)aktivieren, löschen.
 * Dieselben Regeln für Oberfläche und MCP: nur das Besitzer-Team schreibt (MVP-039), Code je Team eindeutig,
 * Klassenfaktor > 0, Standardklasse nicht deaktivierbar, Löschen nur unbenutzt. Nach jeder preisrelevanten
 * Änderung rechnet die Preis-Kaskade des Teams neu.
 *
 * Fehler: NichtGefundenException / GeerbtNurLesenException / NochVerwendetException (RuntimeException) und
 * \InvalidArgumentException für Eingaben — die Meldungen sind für Menschen geschrieben und werden so angezeigt.
 */
class AufschlagsklassenService
{
    public function __construct(private PricingCascadeService $kaskade, private TeamSettingsService $settings) {}

    /** Sichtbar UND eigen — sonst wirft es mit der Meldung, die die Oberfläche zeigt. */
    public function eigene(Team $team, int $id): FoodAlchemistMarkupClass
    {
        $ak = TeamScope::applyVisible(FoodAlchemistMarkupClass::query(), 'team_id', $team)->find($id);
        if ($ak === null) {
            throw new NichtGefundenException('Diese Preisklasse gibt es nicht oder sie ist für dein Team nicht sichtbar.');
        }
        if (! BasisKatalog::mayWrite($ak->team_id, $team)) {
            throw new GeerbtNurLesenException('Preisklasse', 'Diese Preisklasse ist geerbt oder eine Vorgabe. Ändern kann sie nur das Team, dem sie gehört.');
        }

        return $ak;
    }

    public function anlegen(Team $team, array $eingabe): FoodAlchemistMarkupClass
    {
        $code = strtoupper(trim((string) ($eingabe['code'] ?? '')));
        if ($code === '' || trim((string) ($eingabe['label'] ?? '')) === '') {
            throw new \InvalidArgumentException('Code und Bezeichnung sind Pflicht.');
        }
        // Unique ist (team_id, code) — nur im EIGENEN Team prüfen, sonst blockiert (und verrät) ein fremder Code.
        if (FoodAlchemistMarkupClass::where('team_id', $team->id)->where('code', $code)->exists()) {
            throw new \InvalidArgumentException("Code «{$code}» ist schon vergeben.");
        }
        $werte = $this->validiert($eingabe);
        $ak = FoodAlchemistMarkupClass::create($werte + [
            'code' => $code,
            // Spec 94: Bereich nur beim Anlegen — eine Klasse wechselt nie zwischen Speisen und Leistungen
            'bereich' => in_array($eingabe['bereich'] ?? '', FoodAlchemistMarkupClass::BEREICHE, true) ? $eingabe['bereich'] : 'speisen',
            'team_id' => $team->id,
        ]);
        $this->kaskade->recomputeTeam($team);

        return $ak;
    }

    public function aktualisieren(Team $team, int $id, array $eingabe): FoodAlchemistMarkupClass
    {
        $ak = $this->eigene($team, $id);
        $ak->update($this->validiert($eingabe));
        $this->kaskade->recomputeTeam($team);

        return $ak;
    }

    public function aktivUmschalten(Team $team, int $id): FoodAlchemistMarkupClass
    {
        $ak = $this->eigene($team, $id);
        if (! $ak->is_inactive && $this->settings->defaultMarkupClassId($team) === $ak->id) {
            throw new \InvalidArgumentException('Die Standard-Preisklasse kann nicht deaktiviert werden. Bitte zuerst einen anderen Standard wählen.');
        }
        $ak->update(['is_inactive' => ! $ak->is_inactive]);

        return $ak;
    }

    /** Phase 5: hart löschen, wenn unbenutzt (sonst locked → deaktivieren). */
    public function loeschen(Team $team, int $id): void
    {
        $ak = $this->eigene($team, $id);
        // MVP-040: Verwendung nur im SICHTBAREN Set zählen (kein Fremd-Team-Leak). `markup_class_id` ist
        // nullOnDelete, die Sperre ist eine UX-Sicherung. Spec 85 D-1 PR-B: gezählt werden Gerichte, nicht Formen.
        $nRec = TeamScope::applyVisible(DB::table('foodalchemist_recipe_presentations'), 'team_id', $team)
            ->whereNull('deleted_at')->where('markup_class_id', $id)->distinct()->count('recipe_id');
        $nCls = TeamScope::applyVisible(DB::table('foodalchemist_dish_classes'), 'team_id', $team)
            ->whereNull('deleted_at')->where('default_markup_class_id', $id)->count();
        if ($nRec + $nCls > 0) {
            throw new NochVerwendetException("Wird von {$nRec} Gericht(en) + {$nCls} Klasse(n) genutzt — erst umhängen oder deaktivieren.");
        }
        $ak->delete();
    }

    /** Relative Klasse, MwSt-Profil und optionale Rundungsabweichung validieren. */
    private function validiert(array $eingabe): array
    {
        $werte = ['label' => trim((string) ($eingabe['label'] ?? '')), 'note' => ($eingabe['note'] ?? '') ?: null];
        if ($werte['label'] === '') {
            throw new \InvalidArgumentException('Bezeichnung ist Pflicht.');
        }
        $factor = str_replace(',', '.', trim((string) ($eingabe['class_factor_pct'] ?? '')));
        if (! is_numeric($factor) || (float) $factor <= 0) {
            throw new \InvalidArgumentException('Der Klassenfaktor muss größer als 0 sein.');
        }
        $werte['class_factor_pct'] = (float) $factor;
        $werte['vat_profile_key'] = in_array($eingabe['vat_profile_key'] ?? '', ['regulaer', 'ermaessigt'], true)
            ? $eingabe['vat_profile_key'] : null;
        $decimals = trim((string) ($eingabe['rounding_decimals'] ?? ''));
        $werte['rounding_decimals'] = $decimals === '' ? null : max(0, min(4, (int) $decimals));
        $werte['rounding_mode'] = in_array($eingabe['rounding_mode'] ?? '', CatalogPricingService::ROUNDING_MODES, true)
            ? $eingabe['rounding_mode'] : null;

        return $werte;
    }
}
