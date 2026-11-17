<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;

/**
 * Lebendes Format-Kapitel im Foodbook (Entscheid B 2026-08-31): ein Kapitel mit `format_id` trägt keine
 * eigenen Blöcke, die Editionen kommen live aus dem Format. Diese Klasse ist die EINE Quelle dafür —
 * Dokument/PDF (FoodbookService::dokumentDaten) und die Editor-Anzeige lesen hier.
 */
class FormatKapitelService
{
    /**
     * B (2026-08-31): Editionen eines LEBENDEN Format-Kapitels — format_slots (type=concept) → Concept
     * mit Wording-Kette; header/text/spacer = Struktur-Editionen. EINE Quelle für Dokument/PDF
     * ({@see FoodbookService::dokumentDaten}) und die Editor-Anzeige ({@see ansicht}).
     *
     * @return list<array{typ:string, name:?string, claim:?string, text:?string, preis_pp:?float, einzelpreise:bool, gerichte:array}>
     */
    public function editionen(FoodAlchemistFormat $format): array
    {
        $wording = app(WordingResolver::class);
        $editionen = [];
        foreach ($format->slots as $fs) {
            if ($fs->type === 'concept' && $fs->concept !== null) {
                $ed = $fs->concept;
                $editionen[] = [
                    'typ' => 'concept',
                    'name' => $ed->consumer_name ?: $ed->name,
                    'claim' => $ed->claim ?: null,
                    'text' => trim((string) $ed->description) ?: null,
                    'preis_pp' => $ed->price_per_person_cache !== null ? (float) $ed->price_per_person_cache : null,
                    'einzelpreise' => $ed->istEinzelpreis(),
                    'gerichte' => $wording->gerichtZeilen($ed),
                ];
            } elseif (in_array($fs->type, ['header', 'text', 'spacer'], true)) {
                $editionen[] = ['typ' => $fs->type, 'name' => $fs->title, 'text' => $fs->text_content,
                    'claim' => null, 'preis_pp' => null, 'einzelpreise' => false, 'gerichte' => []];
            }
        }

        return $editionen;
    }

    /**
     * Editor-Anzeige eines LEBENDEN Format-Kapitels (format_id gesetzt): die Editionen kommen live aus
     * dem Format ({@see editionen}, dieselbe Quelle wie Dokument/PDF), Pflege nur im Format.
     * Null für normale Kapitel. Format gelöscht → `fehlt` = true, keine Editionen (wie dokumentDaten).
     *
     * @return array{format_id:int, name:?string, fehlt:bool, preis_range:array{min:?float,max:?float}, editionen:list<array>, editionen_n:int, speisen_n:int}|null
     */
    public function ansicht(FoodAlchemistFoodbookKapitel $kapitel): ?array
    {
        if ($kapitel->format_id === null) {
            return null;
        }
        $kapitel->loadMissing([
            'format.slots' => fn ($q) => $q->orderBy('position'),
            'format.slots.concept.slots.dish:id,name,sales_wording_standard',
            'format.slots.concept.slots.package.dishes.dish:id,name,sales_wording_standard',
            'format.slots.concept.slots.embeddedConcept:id,name,consumer_name,price_per_person_cache',
            'format.slots.concept.slots.embeddedConcept.slots.dish:id,name,sales_wording_standard',
            'format.slots.concept.slots.embeddedConcept.slots.package.dishes.dish:id,name,sales_wording_standard',
        ]);
        $format = $kapitel->format;
        if ($format === null) {
            return ['format_id' => (int) $kapitel->format_id, 'name' => null, 'fehlt' => true,
                'preis_range' => ['min' => null, 'max' => null], 'editionen' => [], 'editionen_n' => 0, 'speisen_n' => 0];
        }
        $editionen = $this->editionen($format);
        $konzepte = array_values(array_filter($editionen, fn ($e) => $e['typ'] === 'concept'));

        return [
            'format_id' => (int) $format->id,
            'name' => $format->consumer_name ?: $format->name,
            'fehlt' => false,
            'preis_range' => $format->priceRange(),
            'editionen' => $editionen,
            'editionen_n' => count($konzepte),
            // Speisen wie die Kopf-Kennzahl: Gericht-Zeilen ohne Paket-/Zwischenüberschriften.
            'speisen_n' => (int) collect($konzepte)->sum(fn ($e) => collect($e['gerichte'])
                ->reject(fn ($g) => in_array($g['type'] ?? '', ['paket', 'header'], true))->count()),
        ];
    }
}
