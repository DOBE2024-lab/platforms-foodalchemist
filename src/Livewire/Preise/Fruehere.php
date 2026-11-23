<?php

namespace Platform\FoodAlchemist\Livewire\Preise;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Services\Preis\PreisVerlaufService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * „Frühere Preise (n)“ unter den aktuellen Preisen eines Lieferantenartikels: die Necta-Historie (price_history) als eingeklappte Liste, damit
 * Liste und Preisverlauf dieselben Stände zeigen. Aktionspreise stehen hier markiert (die Linie zeigt sie nicht). Als eigene Komponente mit Key,
 * die nur auf das Ereignis `preise-geaendert` neu lädt (kein Neuzeichnen des Modals).
 *
 * Lesemodus (Spec 65, 98): Der Aufklapper ist Anzeige und bleibt ohne „Bearbeiten“ bedienbar. Ein-/Ausblenden schreibt: die Oberfläche sperrt
 * es über die reaktive Prop $gesperrt (vom LA-Dialog, folgt „Bearbeiten“/„Fertig“ ohne Neu-Mount), der Server über die Bearbeitungssperre
 * des Lieferantenartikels (gleiches Ziel wie der Dialog, eigene Sperre hält das Kind nie).
 */
class Fruehere extends Component
{
    use \Platform\FoodAlchemist\Livewire\Concerns\MitBearbeitungssperre;

    public int $itemId = 0;

    /** Lesemodus des LA-Dialogs: sperrt nur Ein-/Ausblenden, nicht den Aufklapper. */
    #[Reactive]
    public bool $gesperrt = false;

    /** Begründung je Stand-ID (Pflicht zum Ausblenden). */
    public array $gruende = [];

    public ?string $fehler = null;

    public function mount(int $itemId = 0): void
    {
        $this->itemId = $itemId;
    }

    /** Spec 65: Ein-/Ausblenden schreibt in den Lieferantenartikel — gleiche Sperre wie der LA-Dialog. */
    protected function sperrZiel(): ?array
    {
        return $this->itemId > 0 ? ['supplier_item', $this->itemId] : null;
    }

    /** Spec 65 · ohne Sperre erlaubt — schreibt nichts: Neuladen nach `preise-geaendert`. */
    protected function sperrFreiExtra(): array
    {
        return ['neuLaden'];
    }

    #[On('preise-geaendert')]
    public function neuLaden(): void
    {
        // Das Ereignis löst das Neurendern aus; Daten holt render().
    }

    /** Einen Stand der Historie als Erfassungsfehler ausblenden (Grund Pflicht). Nicht löschen: bleibt durchgestrichen sichtbar und einblendbar. */
    public function ausblenden(int $standId): void
    {
        $this->fehler = null;
        $this->tun(fn (PreisVerlaufService $svc, $team, $item) => $svc->ausblenden($team, $item, 'historie', $standId, (string) ($this->gruende[$standId] ?? ''), Auth::id()));
        if ($this->fehler === null) {
            unset($this->gruende[$standId]);
        }
    }

    public function einblenden(int $standId): void
    {
        $this->fehler = null;
        $this->tun(fn (PreisVerlaufService $svc, $team, $item) => $svc->einblenden($team, $item, 'historie', $standId, Auth::id()));
    }

    private function tun(callable $tat): void
    {
        $team = Auth::user()?->currentTeamRelation;
        $item = $team !== null ? FoodAlchemistSupplierItem::query()->visibleToTeam($team)->find($this->itemId) : null;
        if ($item === null) {
            $this->fehler = 'Artikel nicht gefunden.';

            return;
        }
        try {
            $tat(app(PreisVerlaufService::class), $team, $item);
            $this->dispatch('preise-geaendert', itemId: $this->itemId);   // Linie, Trend und Liste neu laden
        } catch (FaRechtFehltException|\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render()
    {
        $team = Auth::user()?->currentTeamRelation;
        $item = FoodAlchemistSupplierItem::query()->visibleToTeam($team)->find($this->itemId);

        return view('foodalchemist::livewire.preise.fruehere', [
            'darfEdit' => $item !== null && Curate::canCurate(Auth::user(), $item),
            'daten' => $item !== null ? app(PreisVerlaufService::class)->fruehere($team, $item) : ['zeilen' => [], 'gesamt' => 0, 'gekappt' => false],
        ]);
    }
}
