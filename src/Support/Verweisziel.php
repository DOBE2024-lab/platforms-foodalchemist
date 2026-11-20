<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\Scopes\TeamSicht;

/**
 * Code-Qualität 1.1c/1.1j: Darf ein Team auf diesen Datensatz VERWEISEN (Slot, Block, Paket-Zeile)?
 *
 * Maßstab ist allein die bestehende Sicht-Regel {@see \Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy::sichtBedingung}
 * — globaler Seed, Master-Kette (Vererbung), Spec-77d-Freigaben, Abo-Zweig — und zwar EINSCHLIESSLICH Fassungen:
 * Mandanten abonnieren Inhalte, ein Verweis auf eine Abo-Fassung oder einen eingefrorenen Stand ist gewollte Fachlogik.
 * Nie „gehört dem eigenen Team“ (isOwnedBy gilt nur für den Datensatz, der geändert wird, nicht für das Verweisziel).
 */
final class Verweisziel
{
    /** @param  class-string  $klasse  Modell mit BelongsToTeamHierarchy */
    public static function sichtbar(string $klasse, Team $team): Builder
    {
        $q = method_exists($klasse, 'mitFassungen') ? $klasse::mitFassungen() : $klasse::query();
        $q->withoutGlobalScope(TeamSicht::class);   // die ausdrückliche Sicht unten ersetzt den automatischen Filter

        return $klasse::sichtBedingung($q, $team, true);
    }

    /** Code-Qualität 2.3f: Vokabular-Felder, die überall dieselbe Sicht-Regel haben. Ein Feld, eine Klasse. */
    public const VOKABULAR = [
        'writing_style_id' => \Platform\FoodAlchemist\Models\FoodAlchemistWritingStyle::class,
        'serving_form_id' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::class,
        'default_serving_form_id' => \Platform\FoodAlchemist\Models\FoodAlchemistServierform::class,
        'event_type_id' => \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::class,
        'default_event_type_id' => \Platform\FoodAlchemist\Models\FoodAlchemistEventtyp::class,
        'service_moment_id' => \Platform\FoodAlchemist\Models\FoodAlchemistEinsatzmoment::class,
        'unit_vocab_id' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::class,
        'device_vocab_id' => \Platform\FoodAlchemist\Models\FoodAlchemistVocabRegenerationDevice::class,
    ];

    /**
     * Wirft, wenn eine gesetzte ID für das Team nicht sichtbar ist. Leer/null = Verknüpfung lösen (erlaubt).
     *
     * @param  class-string  $klasse
     */
    public static function pruefe(string $klasse, Team $team, mixed $id, string $was): void
    {
        $id = (int) ($id ?: 0);
        if ($id > 0 && ! self::sichtbar($klasse, $team)->whereKey($id)->exists()) {
            throw new \RuntimeException("{$was} #{$id} ist nicht sichtbar/vorhanden.");
        }
    }

    /**
     * @param  class-string  $klasse
     * @param  iterable<mixed>  $ids
     */
    public static function pruefeIds(string $klasse, Team $team, iterable $ids, string $was): void
    {
        foreach ($ids as $id) {
            self::pruefe($klasse, $team, $id, $was);
        }
    }

    /**
     * Prüft die Vokabular-Felder aus {@see VOKABULAR} und die CRM-Verknüpfung in $in. Mit $bisher (Update) nur Werte, die
     * sich ändern — ein schon gespeicherter Verweis blockiert keine unabhängige Änderung.
     */
    public static function pruefeFelder(Team $team, array $in, ?\Illuminate\Database\Eloquent\Model $bisher = null): void
    {
        $neu = fn (string $f) => array_key_exists($f, $in) && (int) ($in[$f] ?: 0) !== (int) ($bisher?->{$f} ?? 0);
        foreach (self::VOKABULAR as $feld => $klasse) {
            if ($neu($feld)) {
                self::pruefe($klasse, $team, $in[$feld], $feld);
            }
        }
        if ($neu('crm_company_id') || $neu('crm_contact_id')) {
            CrmKunden::pruefe($team, ($in['crm_company_id'] ?? null) ?: null, ($in['crm_contact_id'] ?? null) ?: null);
        }
    }
}
