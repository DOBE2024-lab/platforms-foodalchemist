<?php

namespace Platform\FoodAlchemist\Services\Position;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Support\BlockReferenz;

/**
 * 98 · 3.6 Positions-Kern: EINE Schreibprüfung für Positionszeilen (Foodbook-Block, Angebots-Block, Konzept-Struktur-Block)
 * — beim Anlegen UND beim Ändern, für Oberfläche und MCP (die Tools rufen die Dienste, die Dienste rufen hier).
 *
 *  - Typ: muss im Profil der Art stehen (Aliasse aufgelöst); unbekannt → Ablehnung (P9, vorher fiel das Foodbook still auf
 *    „text“ zurück).
 *  - Preisbasis: Aliasse kanonisiert (pro_person → person, pro_stueck → pauschal), dann gegen die Basen des Typs geprüft —
 *    auch beim Ändern (vorher prüfte nur Angebot-addBlock). Angebots-Position: unbekannte Basis → pauschal (wie bisher).
 *  - Referenzen (Gericht, Konzept, Darreichung, Einheit): {@see BlockReferenz} — Sicht-Regel inkl. Abo-Fassungen.
 *  - Staffel: nur wo das Profil sie kennt; Stufen sauber (min_persons ≥ 1, Preis ≥ 0, keine doppelte Stufe).
 *
 * Konzept-Slots mit Inhalt (Gericht/Paket) prüfen weiter ConceptSlotZielService und SlotEinheitService (fillSlot).
 */
final class PositionValidierung
{
    /**
     * @param  array<string, mixed>  $daten  bereits auf die Feldliste der Art gefiltert
     * @return array<string, mixed> gesäuberte Daten (kanonischer Typ und Preisbasis)
     */
    public static function block(string $art, Team $team, array $daten, ?Model $zeile = null): array
    {
        $neu = $zeile === null;
        if ($neu || array_key_exists('type', $daten)) {
            $typ = PositionProfil::typAlias($art, (string) ($daten['type'] ?? ''));
            $erlaubt = PositionProfil::typen($art, $neu || $typ !== $zeile?->type);
            if (! in_array($typ, $erlaubt, true)) {
                throw new PositionFehler('Unbekannter Block-Typ "' . ($daten['type'] ?? '') . '". Erlaubt: ' . implode(', ', $erlaubt) . '.');
            }
            $daten['type'] = $typ;
        }
        $effTyp = (string) ($daten['type'] ?? $zeile?->type ?? '');

        if (array_key_exists('price_basis', $daten) && $daten['price_basis'] !== null && $daten['price_basis'] !== '') {
            $basis = PositionProfil::PREISBASIS_ALIAS[$daten['price_basis']] ?? (string) $daten['price_basis'];
            if ($art === PositionProfil::ANGEBOT && $effTyp === 'position') {
                $basis = in_array($basis, FoodAlchemistOfferBlock::POSITION_BASES, true) ? $basis : 'pauschal';   // Spec 94b, wie bisher
            } else {
                $erlaubt = PositionProfil::preisbasen($art, $effTyp) ?: self::allgemein($art);
                if (! in_array($basis, $erlaubt, true)) {
                    throw new PositionFehler('Unbekannte Preis-Basis "' . $daten['price_basis'] . '" für ' . $effTyp . '. Erlaubt: ' . implode(', ', $erlaubt)
                        . ($art === PositionProfil::ANGEBOT ? ' (Staffelpreise gibt es nur im Foodbook).' : '.'));
                }
            }
            $daten['price_basis'] = $basis;
        }
        if ($neu && $art === PositionProfil::ANGEBOT && $effTyp === 'position') {   // Spec 94b: Standard pauschal, Menge 1
            $daten['price_basis'] = in_array($daten['price_basis'] ?? null, FoodAlchemistOfferBlock::POSITION_BASES, true) ? $daten['price_basis'] : 'pauschal';
            $daten['quantity'] ??= 1;
        }

        if (in_array($art, [PositionProfil::FOODBOOK, PositionProfil::ANGEBOT], true)) {
            BlockReferenz::pruefe($team, $daten, $zeile);
        }

        return $daten;
    }

    /**
     * Staffelstufen prüfen und säubern (Vollersatz-Liste).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{min_persons:int, price:float}>
     */
    public static function staffel(string $art, array $rows): array
    {
        if (! PositionProfil::staffel($art)) {
            throw new PositionFehler('Staffelpreise gibt es nur im Foodbook.');
        }
        $aus = [];
        $gesehen = [];
        foreach ($rows as $row) {
            $min = max(1, (int) ($row['min_persons'] ?? 1));
            $preis = (float) ($row['price'] ?? 0);
            if ($preis < 0) {
                throw new PositionFehler('Ein Staffelpreis kann nicht negativ sein.');
            }
            if (isset($gesehen[$min])) {
                throw new PositionFehler("Die Staffelstufe ab {$min} Personen steht doppelt.");
            }
            $gesehen[$min] = true;
            $aus[] = ['min_persons' => $min, 'price' => $preis];
        }

        return $aus;
    }

    /** @return list<string> */
    private static function allgemein(string $art): array
    {
        return match ($art) {
            PositionProfil::FOODBOOK => FoodAlchemistFoodbookBlock::PRICE_BASES,
            PositionProfil::ANGEBOT => FoodAlchemistOfferBlock::PRICE_BASES,
            default => ['person', 'pauschal'],
        };
    }
}
