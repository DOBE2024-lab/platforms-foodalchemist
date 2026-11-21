<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Wo Bilddateien verwendet werden (Bild-Löschschutz, CQ PR 1).
 *
 * Kopien (Foodbook/Speisekarte duplizieren, Branding übernehmen) und veröffentlichte Fassungen (Concept/Format/Abo-Fassung)
 * übernehmen den VERWEIS auf die Datei (context_file_id / Pfad), nicht die Datei. Darum löscht niemand ein Bild direkt, sondern
 * nur über FoodAlchemistMediaService::loescheWennUnbenutzt(): die Datei geht erst weg, wenn keine Zeile dieses Registers sie
 * mehr nutzt — über alle Teams, gelöschte Zeilen eingeschlossen (Wiederherstellen braucht die Datei).
 *
 * REGISTER: Tabelle → Liste von Verweisen. Ein Verweis ist entweder ein Spaltenpaar [context_file_id-Spalte, Pfad-Spalte] oder
 * ['json' => Spalte] für JSON mit Einträgen {context_file_id, path} (beliebig tief verschachtelt) — dazu gehören die Snapshots
 * veröffentlichter Links (Entscheid Kurator 2026-11-20: veröffentlichte Links dürfen nicht brechen).
 * AUSNAHMEN: Bild-/Datei-ähnliche Spalten, die bewusst NICHT im Register stehen, mit Grund.
 * Wächter: Spec98BildLoeschschutzTest (jede passende Spalte steht in genau einer der beiden Listen).
 */
final class BildVerweise
{
    /** @var array<string, list<array{0:string,1:string}|array{json:string}>> */
    public const REGISTER = [
        'foodalchemist_foodbooks' => [
            ['logo_context_file_id', 'logo_path'], ['cover_context_file_id', 'cover_image_path'],
            ['json' => 'presentation_snapshot_json'], ['json' => 'preview_snapshot_json'],
        ],
        'foodalchemist_foodbook_chapters' => [
            ['image_context_file_id', 'image_path'], ['logo_context_file_id', 'logo_path'], ['json' => 'snapshot_json'],
        ],
        'foodalchemist_foodbook_chapter_images' => [['context_file_id', 'path']],
        'foodalchemist_menu_cards' => [
            ['logo_context_file_id', 'logo_path'], ['cover_context_file_id', 'cover_image_path'], ['json' => 'presentation_snapshot_json'],
        ],
        'foodalchemist_menu_card_sections' => [['logo_context_file_id', 'logo_path'], ['json' => 'snapshot_json']],
        'foodalchemist_menu_plans' => [
            ['logo_context_file_id', 'logo_path'], ['cover_context_file_id', 'cover_image_path'], ['json' => 'presentation_snapshot_json'],
        ],
        'foodalchemist_concepts' => [['image_context_file_id', 'image_path']],   // kein Logo am Concept (Schema geprüft)
        'foodalchemist_concept_images' => [['context_file_id', 'path']],
        'foodalchemist_format_images' => [['context_file_id', 'path']],
        'foodalchemist_recipes' => [['image_context_file_id', 'image_path']],
        'foodalchemist_offers' => [
            ['logo_context_file_id', 'logo_path'], ['cover_context_file_id', 'cover_image_path'], ['json' => 'presentation_images_json'],
            ['json' => 'presentation_snapshot_json'],
        ],
        'foodalchemist_offer_chapters' => [['image_context_file_id', 'image_path'], ['logo_context_file_id', 'logo_path']],
        'foodalchemist_offer_chapter_images' => [['context_file_id', 'path']],
        'foodalchemist_konfiguratoren' => [
            ['logo_context_file_id', 'logo_path'], ['cover_context_file_id', 'cover_image_path'],
            ['bild_lunch_context_file_id', 'bild_lunch_path'], ['bild_dinner_context_file_id', 'bild_dinner_path'],
            ['bild_day_night_context_file_id', 'bild_day_night_path'],   // Spec 94 Zeitfenster-Bilder
            ['json' => 'presentation_snapshot_json'],
        ],
        'foodalchemist_konfigurator_konzepte' => [['bild_context_file_id', 'bild_path']],
        'foodalchemist_outlets' => [['logo_context_file_id', 'logo_path']],
        'foodalchemist_recipe_step_photos' => [['context_file_id', 'pfad']],
        // Bild-Blöcke im Struktur-Builder (storeBlockImage): {context_file_id, path} in der Block-style; Design-Kopien teilen sie.
        'foodalchemist_presentation_designs' => [['json' => 'layout_json']],
        // Veröffentlichte Links (Kunden-Links dürfen nicht brechen): Snapshots halten die Datei bis zum nächsten Republish.
        'foodalchemist_presentations' => [['json' => 'snapshot_json']],
        'foodalchemist_handbuecher' => [['json' => 'snapshot_json']],
    ];

    /** Spalte (tabelle.spalte) → Grund, warum sie nicht im Register steht. */
    public const AUSNAHMEN = [
        'foodalchemist_knowledge_documents.source_path' => 'Herkunftsangabe des Wissensdokuments, keine Datei.',
        'foodalchemist_knowledge_sections.heading_path' => 'Überschriften-Pfad im Dokument, keine Datei.',
        'foodalchemist_vocab_pairing_anchors.source_path' => 'Herkunftsangabe, keine Datei.',
        'foodalchemist_suppliers.complaint_path' => 'Beleg/Dokument, nie Ziel einer Kopie.',
        'foodalchemist_supplier_documents.context_file_id' => 'Beleg/Dokument, nie Ziel einer Kopie.',
        'foodalchemist_supplier_invoices.attachment_context_file_id' => 'Beleg/Dokument, nie Ziel einer Kopie.',
        'foodalchemist_delivery_notes.attachment_context_file_id' => 'Beleg/Dokument, nie Ziel einer Kopie.',
        'foodalchemist_produktpaesse.context_file_id' => 'Beleg/Dokument, nie Ziel einer Kopie.',
        'foodalchemist_trend_belege.context_file_id' => 'Beleg/Dokument, nie Ziel einer Kopie.',
        'foodalchemist_mandant_exporte.pfad' => 'Export-Archiv, nie Ziel einer Kopie.',
        'foodalchemist_price_import_runs.datei_pfad' => 'Importdatei einer Preisliste, nie Ziel einer Kopie.',
        'foodalchemist_presentation_fonts.context_file_id' => 'Schriftdatei je Team, nie Ziel einer Kopie (PresentationFontService löscht direkt).',
        'foodalchemist_presentation_fonts.path' => 'Schriftdatei je Team, nie Ziel einer Kopie (PresentationFontService löscht direkt).',
    ];

    /**
     * Eigener Verweis des Aufrufers für loescheWennUnbenutzt($ausser): die Zeile (oder nur ein Spaltenpaar bzw. ein Eintrag der
     * JSON-Spalte) zählt dann nicht als weitere Nutzung — egal, ob die Spalte schon geleert ist oder erst danach geleert wird.
     *
     * @return array{tabelle:string, id:int, spalte:?string, schluessel:?string}
     */
    public static function eigen(Model $zeile, ?string $spalte = null, int|string|null $schluessel = null): array
    {
        return ['tabelle' => $zeile->getTable(), 'id' => (int) $zeile->getKey(), 'spalte' => $spalte,
            'schluessel' => $schluessel === null ? null : (string) $schluessel];
    }
}
