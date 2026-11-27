<?php

namespace Platform\FoodAlchemist\Services\Fassung;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 4: Veröffentlichen eines MASTER-Konzepts als Fassung (Entwurf D1: Zeilen-Fassung).
 * Gilt für kind=concept UND kind=paket (D6). Der alte Paket-Weg (PaketFassungService) ist mit Plan 98 · 2.6c entfallen.
 *
 * Ein Slot zeigt auf GENAU eines von: eingebettetes Paket-Konzept (`embedded_concept_id`, kind=paket),
 * Gericht/Basisrezept (`recipe_id`). Jedes Ziel braucht eine veröffentlichte Fassung (D2/D11), sonst bricht das
 * Veröffentlichen mit einer Liste ab; die Fassung zeigt danach auf die jüngste Fassung des Ziels (Baum eingefroren, §12 B 2).
 * D10: unveröffentlichte Änderungen an einem Rezept sind nur ein Hinweis. D9: eine am Slot gewählte nicht-Standard-Darreichung
 * wird an die Rezept-Fassung angehängt. Leck-Sperre: keine Master-Preise (Konzept-Cache, manueller Preis, Slot-Preise der
 * Layout-Blöcke); der Mandant rechnet selbst über {@see ConceptPreisService}.
 */
class ConceptFassungService extends FassungBasis
{
    /** Spalten, die eine Konzept-Fassung nie vom Arbeitsstand erbt. */
    protected const NIE_KOPIEREN = [
        'uuid', 'legacy_id', 'created_at', 'updated_at', 'deleted_at', 'offer_id', 'kopie_von_id', 'kopie_stand_at', 'template_source_id',
        'price_per_person_cache', 'calculated_price_per_person', 'price_per_person_manual', 'ek_per_person_cache', 'price_incomplete', 'ek_incomplete', 'price_calculated_at',
        'price_calculation_source', 'price_calculation_version', 'price_override_reason', 'price_override_user_id',
        'price_override_at', 'price_override_expires_at', 'nutrition_cache', 'work_time_min_cache',
        'ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von',
        'aenderungsnotiz', 'fassung_allergen_geaendert',
    ];

    /** Slot-Spalten, die nicht in Hash und Kopie gehören bzw. umgehängt werden. `package_id` bleibt gelistet: auf MySQL seit 98 · 3.1
     *  gedroppt, auf SQLite (Testbasis) steht die Spalte noch — so ist der Hash auf beiden gleich. */
    private const SLOT_NIE = ['id', 'uuid', 'team_id', 'concept_id', 'package_id', 'embedded_concept_id', 'recipe_id', 'presentation_id',
        'price_value', 'price_basis', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(
        \Platform\FoodAlchemist\Services\FaRechte $rechte,
        \Platform\FoodAlchemist\Services\Plattform\AboService $abos,
        private FassungService $rezeptFassungen,
        private FassungsDarreichungService $fassungsDarreichungen,
        \Platform\FoodAlchemist\Services\Plattform\PlattformProtokoll $protokoll,
    ) {
        parent::__construct($rechte, $abos, $protokoll);
    }

    protected function modell(): string
    {
        return FoodAlchemistConcept::class;
    }

    protected function rechtText(): string
    {
        return 'Konzept veröffentlichen';
    }

    /** @return array{fassung_id:int, neu:bool, fassung_nr:int, hinweise:list<string>} */
    protected function veroeffentlichenRumpf(Team $master, int $conceptId, string $notiz, ?User $actor): array
    {
        $concept = FoodAlchemistConcept::where('team_id', $master->id)->findOrFail($conceptId);   // Scope: nie eine Fassung
        $slots = $concept->slots()->get();

        [$ziele, $hinweise] = $this->zieleSammeln($slots);
        $hash = $this->inhaltHash($concept, $slots, $ziele);
        $vorher = $this->letzteFassung((int) $concept->id);
        if ($vorher !== null && $vorher->inhalt_hash === $hash) {
            return ['fassung_id' => (int) $vorher->id, 'neu' => false, 'fassung_nr' => (int) $vorher->fassung_nr, 'hinweise' => $hinweise];
        }

        $fassung = DB::transaction(function () use ($concept, $slots, $ziele, $hash, $notiz, $actor, $vorher) {
            $neu = $this->kopiere($concept, $slots, $ziele, $this->naechsteNr((int) $concept->id), $hash, $notiz, $actor);
            $this->markiereAllergenAenderung('concept', $neu, $vorher);

            return $neu;
        });

        $this->nachVeroeffentlichung(['konzept_fassung_veroeffentlicht', ['concept_id' => $concept->id, 'kind' => $concept->kind, 'fassung_id' => $fassung->id,
            'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => $hinweise, 'notiz' => $notiz]], $actor, $fassung,
            fn () => app(FassungSignalService::class)->nachBausteinVeroeffentlichung('concept', [(int) $fassung->id]),
            'Konzept-Fassung veröffentlicht, aber die Signal-Erzeugung ist fehlgeschlagen: ', ['concept_id' => $concept->id]);

        return ['fassung_id' => (int) $fassung->id, 'neu' => true, 'fassung_nr' => (int) $fassung->fassung_nr, 'hinweise' => $hinweise];
    }

    /** Lese-Status für „Veröffentlichen…“ (siehe {@see FassungBasis::statusMitZielen()}). */
    public function status(FoodAlchemistConcept $concept): array
    {
        return $this->statusMitZielen($concept, 'concept', fn ($slots) => $this->zieleSammeln($slots),
            fn ($slots, array $ziele) => $this->inhaltHash($concept, $slots, $ziele),
            function (array $ziele, FassungsVergleich $v) {
                $ids = array_map(fn ($r) => (int) $r->id, array_values($ziele['recipe']));
                foreach ($ziele['concept'] as $c) {
                    array_push($ids, ...$v->rezeptIdsDerKette('concept', (int) $c->id));
                }

                return $ids;
            });
    }

    /**
     * Je Slot-Ziel die jüngste Fassung. Fehlt eine, bricht es mit der Liste ab (D2/D11).
     *
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistConceptSlot>  $slots
     * @return array{0: array{recipe: array<int, FoodAlchemistRecipe>, concept: array<int, FoodAlchemistConcept>}, 1: list<string>}
     */
    private function zieleSammeln($slots): array
    {
        $ziele = ['recipe' => [], 'concept' => []];
        $fehlt = [];
        $hinweise = [];
        foreach ($slots as $s) {
            if ($s->embedded_concept_id !== null && ! isset($ziele['concept'][(int) $s->embedded_concept_id])) {
                $c = FoodAlchemistConcept::mitFassungen()->find($s->embedded_concept_id);
                $f = $c === null ? null : $this->letzteFassung((int) $c->id);
                $f === null ? ($c !== null ? $fehlt[] = "Paket-Konzept „{$c->name}“" : null) : $ziele['concept'][(int) $s->embedded_concept_id] = $f;
            }
            if ($s->recipe_id !== null && ! isset($ziele['recipe'][(int) $s->recipe_id])) {
                $r = FoodAlchemistRecipe::mitFassungen()->find($s->recipe_id);
                $f = $r === null ? null : $this->letzteFassung((int) $r->id, modell: FoodAlchemistRecipe::class);
                if ($f === null) {
                    $r !== null ? $fehlt[] = "Rezept „{$r->name}“" : null;
                } else {
                    $ziele['recipe'][(int) $s->recipe_id] = $f;
                    if ($this->rezeptFassungen->status($r)['weicht_ab']) {
                        $hinweise[] = "Rezept „{$r->name}“ hat unveröffentlichte Änderungen";
                    }
                }
            }
        }
        $this->abbruchMitListe('Noch nicht veröffentlichbar: Diese Bestandteile haben keine veröffentlichte Fassung: ', array_values(array_unique($fehlt)),
            '. Zuerst veröffentlichen (Spec 82 Abo-Entwurf D2/D11).');

        return [$ziele, array_values(array_unique($hinweise))];
    }

    private function inhaltHash(FoodAlchemistConcept $concept, $slots, array $ziele): string
    {
        $kopf = $this->hashKopf($concept, ['id', 'team_id', 'price_mode', 'is_template']);
        $zeilen = $slots->filter(fn ($s) => $this->hatZiel($s, $ziele))->map(function (FoodAlchemistConceptSlot $s) use ($ziele) {
            $felder = collect($s->getAttributes())->except(self::SLOT_NIE)->sortKeys()->all();
            $quelle = $s->presentation_id ? FoodAlchemistRecipeDarreichung::find($s->presentation_id) : null;

            return [$felder,
                null,   // ehemals Alt-Paket (entfallen mit 98 · 2.6c); Platzhalter hält den Inhalts-Hash bestehender Fassungen stabil
                $s->embedded_concept_id !== null ? (int) ($ziele['concept'][(int) $s->embedded_concept_id]->id ?? 0) : null,
                $s->recipe_id !== null ? (int) ($ziele['recipe'][(int) $s->recipe_id]->id ?? 0) : null,
                $quelle === null ? null : [$quelle->only(['serving_form_id', 'quantity_per_unit_g', 'unit_vocab_id', 'unit_count']) + $quelle->inhaltErgaenzung(), (bool) $quelle->is_standard],
            ];
        })->values()->all();

        return hash('sha256', json_encode([$kopf, $zeilen, $this->pivotIds($concept)], JSON_UNESCAPED_UNICODE));
    }

    /** Ein Slot ohne Ziel (Struktur-Block, leerer Slot) bleibt in der Fassung; einer mit gelöschtem Ziel fällt weg. */
    private function hatZiel(FoodAlchemistConceptSlot $s, array $ziele): bool
    {
        if ($s->embedded_concept_id !== null) {
            return isset($ziele['concept'][(int) $s->embedded_concept_id]);
        }
        if ($s->recipe_id !== null) {
            return isset($ziele['recipe'][(int) $s->recipe_id]);
        }

        return true;
    }

    private function kopiere(FoodAlchemistConcept $concept, $slots, array $ziele, int $nr, string $hash, string $notiz, ?User $actor): FoodAlchemistConcept
    {
        $fassung = $this->fassungsKopf($concept, $nr, $hash, $notiz, $actor, ['price_mode' => 'auto', 'is_template' => false]);

        foreach ($slots as $s) {
            if (! $this->hatZiel($s, $ziele)) {
                continue;
            }
            $rezept = $s->recipe_id !== null ? $ziele['recipe'][(int) $s->recipe_id] : null;
            $quelle = $s->presentation_id ? FoodAlchemistRecipeDarreichung::find($s->presentation_id) : null;
            $zeile = $s->replicate(['uuid', 'created_at', 'updated_at', 'deleted_at']);
            $zeile->forceFill([
                'concept_id' => $fassung->id,
                'embedded_concept_id' => $s->embedded_concept_id !== null ? $ziele['concept'][(int) $s->embedded_concept_id]->id : null,
                'recipe_id' => $rezept?->id,
                'presentation_id' => $rezept !== null && $quelle !== null ? $this->fassungsDarreichungen->fassungsDarreichung($rezept, $quelle)?->id : null,
                'price_value' => null, 'price_basis' => null,   // Preise der Layout-Blöcke gehören dem Master
            ])->save();
        }
        $this->pivotsUebernehmen($concept, $fassung);
        foreach ($concept->sectorSuitabilities()->get() as $e) {   // Sektor-Eignung = Eigenschaft des Konzepts, keine Preise
            $e->replicate(['uuid'])->forceFill(['concept_id' => $fassung->id])->save();
        }
        foreach ($concept->images()->get() as $img) {   // Bilder bleiben Verweise auf die Master-Datei (D5)
            $img->replicate(['uuid'])->forceFill(['concept_id' => $fassung->id])->save();
        }

        return $fassung;
    }
}
