<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

/**
 * 98 · 2.4c (U3): ein Anlege-Muster — erst der Dialog mit dem Namen, dann der Datensatz. Vorher legte „Neues Concept“
 * bzw. „Foodbook anlegen“ sofort einen Datensatz „Neues …“ an; brach man ab, blieb er als Leiche stehen.
 *
 * neu() öffnet nur den Dialog (x-fa::anlege-dialog, Modal FA_ANLEGEN), neuAnlegen() prüft den Namen und ruft
 * mitNamenAnlegen() der Komponente. Komponenten mit Bearbeitungssperre tragen neuAnlegen neben neu in sperrFreiExtra ein.
 */
trait LegtMitNamenAn
{
    public string $neuName = '';

    public ?string $neuFehler = null;

    /** Legt den Datensatz mit dem bestätigten Namen an (und wählt ihn aus bzw. öffnet den Editor). */
    abstract protected function mitNamenAnlegen(string $name): void;

    public function neu(): void
    {
        $this->neuName = '';
        $this->neuFehler = null;
        $this->dispatch('modal.open', name: 'fa-anlegen');
    }

    public function neuAnlegen(): void
    {
        $name = trim($this->neuName);
        if ($name === '') {
            $this->neuFehler = 'Bitte einen Namen eingeben.';

            return;
        }
        $this->neuFehler = null;
        $this->dispatch('modal.close', name: 'fa-anlegen');
        $this->mitNamenAnlegen(mb_substr($name, 0, 255));
        $this->neuName = '';
    }
}
