<?php

namespace Platform\FoodAlchemist\Services\PaketUmzug;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\PaketPreisService;

/**
 * Code-Qualität 2.6a/2.6b: Paritäts-Report Alt-Paket ↔ kind=paket-Concept — die Felder, die 2.6b als „alt = neu" nachweisen muss:
 * Preise, Allergene/Diät, Nährwerte, Mengen/Einheiten, Darreichungen, Rechte, Fassungsherkunft, historische Referenzen.
 *
 * Nur lesend. Jedes Feld liefert `ok` + die beiden Werte, damit der Report ohne Nachrechnen lesbar ist. Gerechnete Werte
 * kommen aus denselben Services wie die Anzeige (alt: PaketPreisService/paketAggregat, neu: preisCockpit/conceptAggregat).
 */
final class PaketParitaet
{
    private const TOLERANZ = 0.01;

    public function __construct(private PaketUmzugService $umzug) {}

    /**
     * @return array<string, array{ok: bool, alt: mixed, neu: mixed}>
     */
    public function vergleiche(FoodAlchemistPaket $alt, FoodAlchemistConcept $neu): array
    {
        $agg = app(ConcepterAggregateService::class);
        $aAlt = $this->sicher(fn () => $agg->paketAggregat($alt));
        $aNeu = $this->sicher(fn () => $agg->conceptAggregat($neu));
        $posAlt = $this->umzug->altPositionen($alt);
        $posNeu = $this->umzug->slotPositionen($neu);
        $spalten = fn (array $pos, array $f) => array_map(fn ($p) => array_intersect_key($p, array_flip($f)), $pos);

        $team = Team::find($alt->team_id);
        $vkAlt = $team !== null ? $this->sicher(fn () => app(PaketPreisService::class)->berechne($alt, $team)) : ['fehler' => 'kein Team'];
        $vkNeu = $this->sicher(fn () => app(ConceptService::class)->preisCockpit($neu));

        return [
            'preis_gespeichert' => $this->feld(
                ['vk' => $this->zahl($alt->price_per_person), 'ek' => $this->zahl($alt->ek_per_person), 'modus' => $alt->price_mode],
                ['vk' => $this->zahl($neu->price_per_person_manual ?? $neu->price_per_person_cache), 'ek' => $this->zahl($neu->ek_per_person_cache),
                    'modus' => $neu->price_mode]),
            // Beide unvollständig = gleiche Aussage (alt: vk null, neu: Summe der bekannten Preise + hat_vk_luecke) → ok, Werte bleiben sichtbar
            'preis_gerechnet' => $this->preisFeld(
                ['vk' => $this->zahl($vkAlt['vk_pro_person'] ?? null), 'ek' => $this->zahl($vkAlt['ek_pro_person'] ?? null),
                    'vollstaendig' => (bool) ($vkAlt['vollstaendig'] ?? false)] + $this->fehler($vkAlt),
                ['vk' => $this->zahl($vkNeu['summe_pro_person'] ?? null), 'ek' => $this->zahl($vkNeu['ek_per_person'] ?? null),
                    'vollstaendig' => $this->cockpitVollstaendig($vkNeu)] + $this->fehler($vkNeu)),
            'allergene_diaet' => $this->feld($aAlt['allergene'] ?? $this->fehler($aAlt), $aNeu['allergene'] ?? $this->fehler($aNeu)),
            'naehrwerte' => $this->feld($aAlt['naehrwerte'] ?? $this->fehler($aAlt), $aNeu['naehrwerte'] ?? $this->fehler($aNeu)),
            'ek_arbeitszeit' => $this->feld(
                ['ek' => $this->zahl($aAlt['ek_pro_person'] ?? null), 'min' => $aAlt['work_time_min'] ?? null],
                ['ek' => $this->zahl($aNeu['ek_pro_person'] ?? null), 'min' => $aNeu['work_time_min'] ?? null]),
            'mengen_einheiten' => $this->feld(
                $spalten($posAlt, ['sales_recipe_id', 'quantity', 'unit_vocab_id']), $spalten($posNeu, ['sales_recipe_id', 'quantity', 'unit_vocab_id'])),
            'darreichungen' => $this->feld(
                $spalten($posAlt, ['presentation_id', 'tableware_item_id', 'tableware_alt_item_id']),
                $spalten($posNeu, ['presentation_id', 'tableware_item_id', 'tableware_alt_item_id'])),
            'rechte' => $this->feld(
                ['team_id' => (int) $alt->team_id, 'aktiv' => ! $alt->is_inactive] + $this->freigaben('paket', (int) $alt->id),
                ['team_id' => (int) $neu->team_id, 'aktiv' => $neu->status !== 'archiviert'] + $this->freigaben('concept', (int) $neu->id)),
            'fassungsherkunft' => $this->feld(
                ['ist_fassung' => (bool) $alt->ist_fassung, 'fassung_von_id' => $alt->fassung_von_id, 'kopie_von_id' => $alt->kopie_von_id],
                ['ist_fassung' => (bool) $neu->ist_fassung, 'fassung_von_id' => $neu->fassung_von_id, 'kopie_von_id' => $neu->kopie_von_id ?? null]),
            'historische_referenzen' => $this->referenzen($alt, $neu),
        ];
    }

    /** Concept-Cockpit vollständig: kein leerer Slot, keine EK- und keine VK-Lücke (hat_vk_luecke, CQ 2.6; Zeilen ohne VK als Rückfall). */
    private function cockpitVollstaendig(array $c): bool
    {
        return isset($c['zeilen']) && ! ($c['hat_leer'] ?? false) && ! ($c['hat_ek_luecke'] ?? false) && ! ($c['hat_vk_luecke'] ?? false)
            && collect($c['zeilen'])->every(fn ($z) => ($z['type'] ?? '') !== 'gericht' || (float) ($z['price'] ?? 0) > 0);
    }

    private function preisFeld(array $alt, array $neu): array
    {
        $f = $this->feld($alt, $neu);
        $f['ok'] = $f['ok'] || (($alt['vollstaendig'] ?? true) === false && ($neu['vollstaendig'] ?? true) === false);

        return $f;
    }

    /** Alle Felder ok? */
    public static function gleich(array $report): bool
    {
        return collect($report)->every(fn ($f) => $f['ok']);
    }

    // ── intern ────────────────────────────────────────────────────────────

    /** Slots, die das Alt-Paket einbetten, müssen auf das Concept zeigen; Speiseplan/Bestellvorlagen gibt es nur alt. */
    private function referenzen(FoodAlchemistPaket $alt, FoodAlchemistConcept $neu): array
    {
        $slots = DB::table('foodalchemist_concept_slots')->where('package_id', $alt->id)->whereNull('deleted_at');
        $v = $this->umzug->verweise($alt);
        $a = ['einbettungen' => (clone $slots)->count(), 'speiseplan' => $v['speiseplan'], 'bestellvorlagen' => $v['bestellvorlagen']];
        $n = ['einbettungen' => (clone $slots)->where('embedded_concept_id', $neu->id)->count(), 'speiseplan' => $v['speiseplan'],
            'bestellvorlagen' => $v['bestellvorlagen']];

        // Speiseplan/Bestellvorlagen sind bis 2.6b nicht umgehängt → nur gleich, wenn es keine gibt
        return ['ok' => $a['einbettungen'] === $n['einbettungen'] && $v['speiseplan'] === 0 && $v['bestellvorlagen'] === 0, 'alt' => $a, 'neu' => $n];
    }

    /** @return array{abo: int, sammlung: int} */
    private function freigaben(string $typ, int $id): array
    {
        return [
            'abo' => DB::table('foodalchemist_abo_objekte')->where('typ', $typ)->where('objekt_id', $id)->count(),
            'sammlung' => DB::table('foodalchemist_sammlung_objekte')->where('typ', $typ)->where('objekt_id', $id)->count(),
        ];
    }

    private function feld(mixed $alt, mixed $neu): array
    {
        return ['ok' => $this->gleichWert($alt, $neu), 'alt' => $alt, 'neu' => $neu];
    }

    private function gleichWert(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (! $this->gleichWert($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) <= self::TOLERANZ;
        }

        return $a === $b;
    }

    private function zahl(mixed $v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    /** @return array<string, mixed> */
    private function sicher(callable $f): array
    {
        try {
            return (array) $f();
        } catch (\Throwable $e) {
            return ['fehler' => class_basename($e).': '.$e->getMessage()];
        }
    }

    /** @return array<string, string> */
    private function fehler(array $r): array
    {
        return isset($r['fehler']) ? ['fehler' => (string) $r['fehler']] : [];
    }
}
