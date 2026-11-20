<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Models\FoodAlchemistPresentationDesign;

/**
 * EK-Ansicht im veröffentlichten Link (2026-11-20): Design-Haken `tokens_json.ek_im_link`.
 * Ohne Haken bleibt der Link wie bisher (kein EK im Snapshot, kein Knopf). Mit Haken friert
 * publish den Snapshot MIT EK ein (nur Foodbook und Angebot, siehe PresentationService::buildSnapshot)
 * und der Link zeigt den Knopf „EK einblenden“. Ohne ?ek=1 wird der EK vor dem Rendern entfernt.
 * Bewusste Entscheidung: jeder mit dem Link kann umschalten, der Hinweis „intern“ bleibt sichtbar.
 */
final class PraesentationsEkLink
{
    /** Ist der Haken im gebundenen Team-Design gesetzt? Eingebaute Designs haben ihn nie. */
    public static function imDesign(string $designSource): bool
    {
        if (! str_starts_with($designSource, 'design:')) {
            return false;
        }
        $tokens = FoodAlchemistPresentationDesign::query()->find((int) substr($designSource, 7))?->tokens_json;

        return is_array($tokens) && ! empty($tokens['ek_im_link']);
    }

    /**
     * Öffentliche Ansicht eines Snapshots: nur ein mit EK veröffentlichter Snapshot bekommt den Knopf.
     * Ohne ?ek=1 gehen Kennzeichen und alle EK-Werte vor dem Rendern raus.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public static function ansicht(array $snapshot, bool $ekGewuenscht): array
    {
        if (empty($snapshot['ansicht']['ek'])) {
            return $snapshot;
        }
        $snapshot['ek_schalter'] = true;
        if (! $ekGewuenscht) {
            unset($snapshot['ansicht']);
            $snapshot['content']['sections'] = PraesentationsPreise::ohneEk($snapshot['content']['sections'] ?? []);
        }

        return $snapshot;
    }
}
