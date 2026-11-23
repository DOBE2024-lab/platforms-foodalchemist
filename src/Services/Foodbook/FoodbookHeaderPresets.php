<?php

namespace Platform\FoodAlchemist\Services\Foodbook;

/**
 * 98 · 3.3 S4-0k: Header-Presets für den „+ Inhalt"-Picker (Jarvis-Parität), aus FoodbookService::headerPresets verschoben.
 * Reine Daten ohne Dienst-Abhängigkeit: OfferCompositionService liest sie hier statt über FoodbookService (Rückkante aus dem
 * großen Kreis), FoodbookService::headerPresets bleibt als Fassade.
 */
final class FoodbookHeaderPresets
{
    /**
     * @return array<string, list<array{slug:string, label:string, type:string, preis_basis?:string, sichtbar?:bool}>>
     */
    public static function liste(): array
    {
        $gang = fn ($slug, $label) => ['slug' => "gang.$slug", 'label' => $label, 'type' => 'header_neutral'];
        $zeit = fn ($slug, $label) => ['slug' => "zeit.$slug", 'label' => $label, 'type' => 'header_neutral'];

        return [
            'Gänge / Service' => [
                $gang('get_together', 'Get-together'), $gang('aperitif', 'Aperitif'), $gang('flying', 'Flying'),
                $gang('vorspeisen', 'Vorspeisen'), $gang('suppen', 'Suppen'), $gang('zwischengang', 'Zwischengang'),
                $gang('hauptgang', 'Hauptgang'), $gang('beilagen', 'Beilagen'), $gang('dessert', 'Dessert'),
                $gang('kaese', 'Käse'), $gang('buffet', 'Buffet'), $gang('fingerfood', 'Fingerfood'),
                $gang('snacks', 'Snacks'), $gang('late_night', 'Late Night'), $gang('getraenke', 'Getränke'),
                $gang('kaffee_tee', 'Kaffee & Tee'),
            ],
            'Tageszeit' => [
                $zeit('breakfast', 'Breakfast'), $zeit('brunch', 'Brunch'), $zeit('lunch', 'Lunch'),
                $zeit('coffee_break', 'Coffee Break'), $zeit('dinner', 'Dinner'), $zeit('after_work', 'After Work'),
            ],
            'Konzept / Format (+ Preis)' => [
                ['slug' => 'format.menue_paket', 'label' => 'Menü-Paket', 'type' => 'header_frei_preis', 'price_basis' => 'person'],
                ['slug' => 'format.buffet_paket', 'label' => 'Buffet-Paket', 'type' => 'header_frei_preis', 'price_basis' => 'person'],
                ['slug' => 'format.flat_rate', 'label' => 'Flat-Rate', 'type' => 'header_frei_preis', 'price_basis' => 'pauschal'],
                ['slug' => 'format.staffelpreis_block', 'label' => 'Staffelpreis-Block', 'type' => 'header_frei_preis', 'price_basis' => 'staffel'],
            ],
            'Intern (nicht sichtbar)' => [
                ['slug' => 'intern.kalkulation', 'label' => 'Interne Kalkulation', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.personal', 'label' => 'Personal', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.logistik', 'label' => 'Logistik', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.equipment', 'label' => 'Equipment', 'type' => 'header_neutral', 'visible' => false],
                ['slug' => 'intern.bemerkungen', 'label' => 'Bemerkungen', 'type' => 'header_neutral', 'visible' => false],
            ],
        ];
    }
}
