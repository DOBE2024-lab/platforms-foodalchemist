<?php

namespace Platform\FoodAlchemist\Services\Fassung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 7: Veröffentlichen eines MASTER-Formats als Fassung (Entwurf D1: Zeilen-Fassung). Spiegelt {@see ConceptFassungService}.
 *
 * - Nur im Master-Team, Rolle Freigeben, Änderungsnotiz Pflicht.
 * - **D8 (Kunden-IP): ein Format mit `origin = kunde` lässt sich nie veröffentlichen/abonnieren** — hart hier, in AboService (Hülle, abonnieren) und beim Befüllen einer abonnierten
 *   Sammlung. Wer ein Kunden-Format als Abo-Inhalt braucht, hat ein Herkunfts-Problem, kein Technik-Problem.
 * - D2: jedes Konzept in den Slots braucht eine veröffentlichte Fassung (Liste bei Abbruch); die Fassung zeigt auf die jüngste Fassung je Konzept.
 * - Ein Format trägt keine Kosten und keinen Preis: nichts davon geht mit (und wird beim Mandanten live aus den Konzept-Fassungen gerechnet, PR 4).
 * - Slot-Wording-Overrides (`payload_json.wording_overrides`, geschlüsselt nach Konzept-SLOT-IDs) werden auf die Slots der Konzept-Fassung umgeschlüsselt (Position für Position),
 *   sonst zeigten sie ins Leere.
 * - Bilder (Galerie/Hero) bleiben Verweise auf die Master-Datei (D5); Kopie erst bei Kündigung/Eigene Kopie.
 */
class FormatFassungService extends FassungBasis
{
    /** Spalten, die eine Format-Fassung nie vom Arbeitsstand erbt. */
    protected const NIE_KOPIEREN = [
        'uuid', 'legacy_id', 'created_at', 'updated_at', 'deleted_at', 'kopie_von_id', 'kopie_stand_at',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von', 'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    private const SLOT_NIE = ['id', 'uuid', 'team_id', 'format_id', 'concept_id', 'created_at', 'updated_at', 'deleted_at'];

    private const KUNDEN_IP = 'Ein Kunden-Format (origin=kunde) ist nie abonnierbar und lässt sich nicht veröffentlichen (Kunden-IP, D8).';

    protected function modell(): string
    {
        return FoodAlchemistFormat::class;
    }

    protected function rechtText(): string
    {
        return 'Format veröffentlichen';
    }

    /** @return array{fassung_id:int, neu:bool, fassung_nr:int, hinweise:list<string>} */
    protected function veroeffentlichenRumpf(Team $master, int $formatId, string $notiz, ?User $actor): array
    {
        $format = FoodAlchemistFormat::where('team_id', $master->id)->findOrFail($formatId);   // Scope: nie eine Fassung
        if ($format->origin === 'kunde') {
            throw new \RuntimeException(self::KUNDEN_IP);
        }
        $slots = $format->slots()->get();
        $ziele = $this->zieleSammeln($slots);
        $hash = $this->inhaltHash($format, $slots, $ziele);
        $vorher = $this->letzteFassung((int) $format->id);
        if ($vorher !== null && $vorher->inhalt_hash === $hash) {
            return ['fassung_id' => (int) $vorher->id, 'neu' => false, 'fassung_nr' => (int) $vorher->fassung_nr, 'hinweise' => []];
        }

        $fassung = DB::transaction(function () use ($format, $slots, $ziele, $hash, $notiz, $actor, $vorher) {
            $neu = $this->kopiere($format, $slots, $ziele, $this->naechsteNr((int) $format->id), $hash, $notiz, $actor);
            $this->markiereAllergenAenderung('format', $neu, $vorher);

            return $neu;
        });

        $this->nachVeroeffentlichung(['format_fassung_veroeffentlicht', ['format_id' => $format->id, 'fassung_id' => $fassung->id, 'fassung_nr' => (int) $fassung->fassung_nr, 'notiz' => $notiz]],
            $actor, $fassung, fn () => app(FassungSignalService::class)->nachBausteinVeroeffentlichung('format', [(int) $fassung->id]),
            'Format-Fassung veröffentlicht, aber die Signal-Erzeugung ist fehlgeschlagen: ', ['format_id' => $format->id]);

        return ['fassung_id' => (int) $fassung->id, 'neu' => true, 'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => []];
    }

    /** Lese-Status für „Veröffentlichen…“ (siehe {@see FassungBasis::statusMitZielen()}). Ein Kunden-Format ist `blockiert` (D8). */
    public function status(FoodAlchemistFormat $format): array
    {
        $slots = null;

        return $this->statusMitZielen($format, 'format',
            function () use ($format, &$slots) {
                $slots = $format->slots()->get();

                return [$this->zieleSammeln($slots), []];
            },
            function (array $ziele) use ($format, &$slots) {
                return $this->inhaltHash($format, $slots, $ziele);
            },
            function (array $ziele, FassungsVergleich $v) {
                $ids = [];
                foreach ($ziele as $c) {
                    array_push($ids, ...$v->rezeptIdsDerKette('concept', (int) $c->id));
                }

                return $ids;
            },
            $format->origin === 'kunde' ? self::KUNDEN_IP : null);
    }

    /**
     * Je Konzept-Slot die jüngste Konzept-Fassung. Fehlt eine, bricht es mit der Liste ab (D2).
     *
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistFormatSlot>  $slots
     * @return array<int, FoodAlchemistConcept>  Konzept-Arbeitszeile → jüngste Fassung
     */
    private function zieleSammeln($slots): array
    {
        $ziele = [];
        $fehlt = [];
        foreach ($slots as $s) {
            if ($s->concept_id === null || isset($ziele[(int) $s->concept_id])) {
                continue;
            }
            $c = FoodAlchemistConcept::mitFassungen()->find($s->concept_id);
            if ($c === null) {
                continue;   // gelöschtes Konzept fällt weg, wie im Arbeitsstand
            }
            $f = $this->letzteFassung((int) $c->id, modell: FoodAlchemistConcept::class);
            $f === null ? $fehlt[] = (string) $c->name : $ziele[(int) $s->concept_id] = $f;
        }
        $this->abbruchMitListe('Noch nicht veröffentlichbar: Diese Konzepte im Format haben keine veröffentlichte Fassung: ', array_values(array_unique($fehlt)),
            '. Zuerst veröffentlichen (Spec 82 Abo-Entwurf D2).');

        return $ziele;
    }

    /** @param array<int, FoodAlchemistConcept> $ziele */
    private function inhaltHash(FoodAlchemistFormat $format, $slots, array $ziele): string
    {
        $kopf = $this->hashKopf($format, ['id', 'team_id', 'status']);
        $zeilen = $slots->filter(fn ($s) => $s->concept_id === null || isset($ziele[(int) $s->concept_id]))->map(function (FoodAlchemistFormatSlot $s) use ($ziele) {
            $felder = collect($s->getAttributes())->except(array_merge(self::SLOT_NIE, ['payload_json']))->sortKeys()->all();

            return [$felder, $s->concept_id !== null ? (int) $ziele[(int) $s->concept_id]->id : null, $this->payloadUmgeschluesselt($s, $ziele)];
        })->values()->all();

        return hash('sha256', json_encode([$kopf, $zeilen, $this->pivotIds($format)], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Wording-Overrides eines Slots (Konzept-Slot-ID → Text) auf die Slots der Konzept-FASSUNG umschlüsseln: der n-te Slot der Arbeitszeile (nach Position, ID) wird der n-te
     * Slot der Fassung. Stimmt die Zahl nicht mehr (unveröffentlichte Änderung am Konzept), entfallen die Overrides, die nicht zuzuordnen sind.
     *
     * @param  array<int, FoodAlchemistConcept>  $ziele
     * @return array<string, mixed>
     */
    private function payloadUmgeschluesselt(FoodAlchemistFormatSlot $s, array $ziele): array
    {
        $payload = (array) ($s->payload_json ?? []);
        if ($s->concept_id === null || ! isset($ziele[(int) $s->concept_id]) || empty($payload['wording_overrides'])) {
            return $payload;
        }
        $alt = DB::table('foodalchemist_concept_slots')->where('concept_id', $s->concept_id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->pluck('id')->values()->all();
        $neu = DB::table('foodalchemist_concept_slots')->where('concept_id', $ziele[(int) $s->concept_id]->id)->whereNull('deleted_at')->orderBy('position')->orderBy('id')->pluck('id')->values()->all();
        $umgeschluesselt = [];
        foreach ((array) $payload['wording_overrides'] as $slotId => $text) {
            $i = array_search((int) $slotId, array_map('intval', $alt), true);
            if ($i !== false && isset($neu[$i])) {
                $umgeschluesselt[(string) $neu[$i]] = $text;
            }
        }
        $payload['wording_overrides'] = $umgeschluesselt;

        return $payload;
    }

    /** @param array<int, FoodAlchemistConcept> $ziele */
    private function kopiere(FoodAlchemistFormat $format, $slots, array $ziele, int $nr, string $hash, string $notiz, ?User $actor): FoodAlchemistFormat
    {
        $fassung = $this->fassungsKopf($format, $nr, $hash, $notiz, $actor);
        foreach ($slots as $s) {
            if ($s->concept_id !== null && ! isset($ziele[(int) $s->concept_id])) {
                continue;
            }
            $s->replicate(['uuid', 'created_at', 'updated_at', 'deleted_at'])->forceFill([
                'format_id' => $fassung->id,
                'concept_id' => $s->concept_id !== null ? $ziele[(int) $s->concept_id]->id : null,
                'payload_json' => $this->payloadUmgeschluesselt($s, $ziele) ?: null,
            ])->save();
        }
        $this->pivotsUebernehmen($format, $fassung);
        foreach ($format->images()->get() as $bild) {   // Bilder bleiben Verweise auf die Master-Datei (D5)
            $bild->replicate(['uuid'])->forceFill(['format_id' => $fassung->id])->save();
        }

        return $fassung;
    }
}
