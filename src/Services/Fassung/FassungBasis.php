<?php

namespace Platform\FoodAlchemist\Services\Fassung;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;
use Platform\FoodAlchemist\Services\Plattform\AboService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\Plattform\PlattformProtokoll;
use Platform\FoodAlchemist\Support\MasterTeam;

/**
 * 98 · 3.2 · Gemeinsamer Ablauf der Fassungs-Dienste (Spec 82 §12 B): Rezept ({@see FassungService}), Konzept/Paket
 * ({@see ConceptFassungService}) und Format ({@see FormatFassungService}).
 *
 * Hier liegt nur, was in den Arten wortgleich war. Unterschiede bleiben Parameter (Team-Sicht-Grund, gelöschte Fassungen,
 * Ausnahme-Felder, Zusatz-Spalten) — nichts wurde angeglichen. Die Inhalts-Hashes sichert FassungHashZwillingTest gegen
 * eine eingefrorene Kopie ab.
 */
abstract class FassungBasis
{
    /** Spalten, die eine Fassung nie vom Arbeitsstand erbt (je Art). */
    protected const NIE_KOPIEREN = [];

    public function __construct(
        protected FaRechte $rechte,
        protected AboService $abos,
        protected PlattformProtokoll $protokoll,
    ) {}

    /** @return class-string<Model> Modell der Arbeitszeile/Fassung */
    abstract protected function modell(): string;

    /** @return array<string, mixed> */
    abstract protected function veroeffentlichenRumpf(Team $master, int $id, string $notiz, ?User $actor): array;

    /**
     * Template: Lesen im Master (Spec 84 B3 C; das Recht prüft der Rumpf am übergebenen Team + Nutzer, nicht am Kontext),
     * Master-Team, Recht „Freigeben“, Änderungsnotiz Pflicht; der Rumpf bekommt die getrimmte Notiz.
     *
     * @return array<string, mixed>
     */
    public function veroeffentlichen(Team $master, int $id, string $notiz, ?User $actor): array
    {
        return TeamSicht::lesenIm($master, function () use ($master, $id, $notiz, $actor) {
            if (! MasterTeam::istMaster($master)) {
                throw new \RuntimeException('Veröffentlichen gibt es nur im Master-Team (Spec 82 §12 B).');
            }
            $this->rechte->pruefe($actor, $master, FaRolle::Freigeben, $this->rechtText());
            $notiz = trim($notiz);
            if ($notiz === '') {
                throw new \RuntimeException('Beim Veröffentlichen ist eine Änderungsnotiz Pflicht (Änderungsprotokoll der Abonnenten).');
            }

            return $this->veroeffentlichenRumpf($master, $id, $notiz, $actor);
        });
    }

    /** Text für die Rechte-Prüfung, z. B. „Konzept veröffentlichen“. */
    abstract protected function rechtText(): string;

    /** Abfrage-Basis: inklusive Fassungen, optional ohne Team-Sicht (mit Grund). */
    protected function abfrage(?string $sichtGrund = null, ?string $modell = null): Builder
    {
        $q = ($modell ?? $this->modell())::mitFassungen();

        return $sichtGrund === null ? $q : $q->ohneTeamSicht($sichtGrund);
    }

    /** Jüngste Fassung einer Arbeitszeile (`$modell` für Ziele anderer Art, z. B. Rezepte im Konzept). */
    protected function letzteFassung(int $vonId, bool $ohneGeloeschte = true, ?string $sichtGrund = null, ?string $modell = null): ?Model
    {
        $q = $this->abfrage($sichtGrund, $modell)->where('fassung_von_id', $vonId)->where('ist_fassung', true);

        return ($ohneGeloeschte ? $q->whereNull('deleted_at') : $q)->orderByDesc('fassung_nr')->orderByDesc('id')->first();
    }

    /** Nummer über ALLE Fassungen der Familie, auch soft-gelöschte (UNIQUE-Schlüssel zählen sie mit). */
    protected function naechsteNr(int $vonId, ?string $sichtGrund = null): int
    {
        return (int) $this->abfrage($sichtGrund)->withTrashed()->where('fassung_von_id', $vonId)->where('ist_fassung', true)->max('fassung_nr') + 1;
    }

    /** Kopie der Arbeitszeile ohne NIE_KOPIEREN, als Fassung markiert; `$vorweg` sind Spalten je Art (vor den Fassungs-Spalten gesetzt). */
    protected function fassungsKopf(Model $arbeitszeile, int $nr, string $hash, string $notiz, ?User $actor, array $vorweg = []): Model
    {
        $fassung = $arbeitszeile->replicate(static::NIE_KOPIEREN);
        $fassung->forceFill($vorweg + [
            'ist_fassung' => true, 'fassung_von_id' => $arbeitszeile->id, 'fassung_nr' => $nr, 'inhalt_hash' => $hash,
            'veroeffentlicht_at' => now(), 'veroeffentlicht_von' => $actor?->id, 'aenderungsnotiz' => $notiz,
        ])->save();

        return $fassung;
    }

    /**
     * Kopf-Felder für den Inhalts-Hash: alle Spalten ohne `$ohne` + NIE_KOPIEREN, ohne `*_at`, nach Schlüssel sortiert.
     *
     * @param  (callable(mixed, string): bool)|null  $auchOhne  weitere Spalten je Art (Rezept: abgeleitete Werte)
     * @return array<string, mixed>
     */
    protected function hashKopf(Model $zeile, array $ohne, ?callable $auchOhne = null): array
    {
        $kopf = collect($zeile->getAttributes())->except(array_merge($ohne, static::NIE_KOPIEREN));

        return ($auchOhne === null ? $kopf : $kopf->reject($auchOhne))->reject(fn ($v, $k) => str_ends_with($k, '_at'))->sortKeys()->all();
    }

    /**
     * Nach dem Veröffentlichen: Bibliothek der Abonnenten nachziehen, Protokoll, Signale. Signale und Folgen sind nie ein
     * Grund, die Veröffentlichung scheitern zu lassen.
     */
    protected function nachVeroeffentlichung(?array $protokoll, ?User $actor, ?Model $bezug, callable $signale, string $meldung, array $kontext): void
    {
        $this->abos->neuBerechnenAlle();
        if ($protokoll !== null) {
            $this->protokoll->schreibe($protokoll[0], $protokoll[1], $actor, $bezug);
        }
        try {
            $signale();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error($meldung.$e->getMessage(), ['exception' => $e] + $kontext);
        }
    }

    /** @return ?array{id:int, nr:int, veroeffentlicht_at:?string, notiz:?string} */
    protected function statusLetzte(?Model $letzte, callable $datum): ?array
    {
        return $letzte === null ? null : ['id' => (int) $letzte->id, 'nr' => (int) $letzte->fassung_nr,
            'veroeffentlicht_at' => $datum($letzte->veroeffentlicht_at), 'notiz' => $letzte->aenderungsnotiz];
    }

    /** Allergen-/Zusatzstoff-Erbe über die Kette (Konzept, Format): Flag an der Fassung, wenn sich das Bild gegenüber der Vorgängerin ändert. */
    protected function markiereAllergenAenderung(string $art, Model $fassung, ?Model $vorher): void
    {
        if ($vorher === null) {
            return;
        }
        $v = app(FassungsVergleich::class);
        if ($v->bilderDiff($v->kettenBild($art, (int) $vorher->id), $v->kettenBild($art, (int) $fassung->id)) !== []) {
            $fassung->forceFill(['fassung_allergen_geaendert' => true])->save();
        }
    }

    /**
     * Konzept/Format: Lese-Status für die Oberfläche „Veröffentlichen…“ — rechnet nichts um und schreibt nichts (Spec 82 §13 B6, PR 8).
     *
     * @param  callable(\Illuminate\Support\Collection): array{0: array, 1: list<string>}  $ziele  Ziele + Hinweise aus den Slots; RuntimeException ⇒ `blockiert`
     * @param  callable(\Illuminate\Support\Collection, array): string  $hash
     * @param  callable(array, FassungsVergleich): list<int>  $rezeptIds  Rezepte der Kette zum Allergen-Vergleich
     * @return array{letzte: ?array{id:int, nr:int, veroeffentlicht_at:?string, notiz:?string}, weicht_ab: bool, blockiert: ?string, hinweise: list<string>, allergen_aenderung: bool, allergen_felder: list<string>}
     */
    protected function statusMitZielen(Model $arbeitszeile, string $art, callable $ziele, callable $hash, callable $rezeptIds, ?string $blockiert = null): array
    {
        $letzte = $this->letzteFassung((int) $arbeitszeile->id);
        $out = ['letzte' => $this->statusLetzte($letzte, fn ($d) => $d?->format('d.m.Y H:i')), 'weicht_ab' => true, 'blockiert' => null, 'hinweise' => [],
            'allergen_aenderung' => false, 'allergen_felder' => []];
        if ($blockiert !== null) {
            $out['blockiert'] = $blockiert;

            return $out;
        }
        try {
            $slots = $arbeitszeile->slots()->get();
            [$z, $hinweise] = $ziele($slots);
        } catch (\RuntimeException $e) {
            $out['blockiert'] = $e->getMessage();

            return $out;
        }
        $out['hinweise'] = $hinweise;
        $out['weicht_ab'] = $letzte === null || $letzte->inhalt_hash !== $hash($slots, $z);
        if ($letzte !== null) {
            $v = app(FassungsVergleich::class);
            $diff = $v->bilderDiff($v->kettenBild($art, (int) $letzte->id), $v->kettenBildAus(array_values(array_unique($rezeptIds($z, $v)))));
            $out['allergen_aenderung'] = $diff !== [];
            $out['allergen_felder'] = array_keys($diff);
        }

        return $out;
    }

    /** Abbruch mit Liste (höchstens 10 Namen, dann „…“): `$vorsatz` + Namen + `$nachsatz`. */
    protected function abbruchMitListe(string $vorsatz, array $namen, string $nachsatz): void
    {
        if ($namen !== []) {
            throw new \RuntimeException($vorsatz.implode(', ', array_slice($namen, 0, 10)).(count($namen) > 10 ? ' …' : '').$nachsatz);
        }
    }
}
