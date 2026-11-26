<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\Einkauf\LeadLaService;
use Platform\FoodAlchemist\Services\GpStamm\PlatzhalterDeklarationService;

/**
 * Einmalig (19.11.2026): Platzhalter, deren Werte fachlich eindeutig sind, mit Allergenen, Zusatzstoffen und Nährwerten von Hand vorbelegen
 * ({@see PlatzhalterDeklarationService}). Eindeutig ist nur, was in {@see self::VORBELEGUNG} steht — heute Leitungswasser. Abstrakte
 * Platzhalter (Fett, Säure, Süßung, Fond, Püree …) stehen für viele Zutaten und bleiben leer.
 *
 * Nur Platzhalter ohne jede Hand-Angabe (kein Allergen-Override, keine Zusatzstoffe von Hand, keine Nährwerte) — gepflegte Werte bleiben.
 * Trockenlauf = Default; --apply braucht --backup-nachweis; --zurueck setzt die Spalten aus dem Protokoll zurück. Danach rechnen die Rezepte
 * mit diesen Platzhaltern neu (bestehender Pfad {@see LeadLaService::recomputeNutzerFuerGps}).
 */
class PlatzhalterDeklarationVorbelegenCommand extends Command
{
    /** Slug → Eingabe für {@see PlatzhalterDeklarationService::setzen()}. Nur fachlich eindeutige Platzhalter. */
    public const VORBELEGUNG = [
        'platzhalter_leitungswasser' => [
            'alle_allergene' => 'nicht_enthalten',
            'alle_zusatzstoffe' => 'nein',
            'naehrwerte' => ['kcal' => 0, 'protein_g' => 0, 'fat_g' => 0, 'saturated_fat_g' => 0, 'carbs_g' => 0, 'sugar_g' => 0, 'salt_g' => 0],
        ],
    ];

    /** Spalten, die die Vorbelegung schreibt — für Protokoll und Zurücknehmen. */
    private const SPALTEN = ['allergens_source', 'allergens_confidence', 'allergens_aggregated_at', 'deklaration_manuell', 'nutri_source', 'nutri_ai_confidence',
        'nutri_kcal_per_100g', 'nutri_protein_g_per_100g', 'nutri_fat_g_per_100g', 'nutri_saturated_fat_g_per_100g', 'nutri_carbs_g_per_100g',
        'nutri_sugar_g_per_100g', 'nutri_salt_g_per_100g'];

    protected $signature = 'foodalchemist:platzhalter-deklaration-vorbelegen
        {--apply : wirklich schreiben}
        {--backup-nachweis= : Pfad zum Backup-Dump (Pflicht bei --apply; nicht älter als 24 h)}
        {--bericht= : Ordner für das Protokoll (Standard: storage/app/fa-platzhalter-deklaration)}
        {--zurueck= : Protokoll (JSON) eines --apply-Laufs zurücknehmen}';

    protected $description = 'Eindeutige Platzhalter (Leitungswasser) mit Allergenen, Zusatzstoffen und Nährwerten von Hand vorbelegen';

    public function handle(PlatzhalterDeklarationService $dienst, LeadLaService $leads): int
    {
        $bericht = (string) ($this->option('bericht') ?: storage_path('app/fa-platzhalter-deklaration'));
        File::ensureDirectoryExists($bericht);

        if (($z = $this->option('zurueck')) !== null) {
            $p = json_decode((string) file_get_contents((string) $z), true);
            foreach (($p['vorher'] ?? []) as $id => $alt) {
                DB::table('foodalchemist_gps')->where('id', (int) $id)->update([...$alt, 'updated_at' => now()]);
            }
            $n = $leads->recomputeNutzerFuerGps(array_map('intval', array_keys($p['vorher'] ?? [])));
            $this->info('Zurückgenommen: ' . count($p['vorher'] ?? []) . " Platzhalter, {$n} Rezepte neu gerechnet.");

            return self::SUCCESS;
        }

        $alle = FoodAlchemistGp::query()->where('is_platzhalter', true)->orderBy('team_id')->orderBy('name')->get();
        $plan = [];
        $zeilen = [];
        foreach ($alle as $gp) {
            $vorbelegung = self::VORBELEGUNG[(string) $gp->main_ingredient_slug] ?? null;
            $nutzung = DB::table('foodalchemist_recipe_ingredients')->where('gp_id', $gp->id)->whereNull('deleted_at')->count();
            $grund = match (true) {
                $vorbelegung === null => 'unklar, bleibt leer',
                $gp->team_id === null => 'global, bleibt leer (Pflege durch den Master)',
                $this->hatHandwerte($gp) => 'schon gepflegt, bleibt',
                default => 'vorbelegen',
            };
            if ($grund === 'vorbelegen') {
                $plan[] = $gp;
            }
            $zeilen[] = [$gp->id, $gp->team_id ?? 'global', $gp->name, $nutzung, $grund];
        }
        $this->table(['GP', 'Team', 'Platzhalter', 'Rezeptzeilen', 'Ergebnis'], $zeilen);
        $this->line('Vorbelegen: ' . count($plan) . ' von ' . $alle->count() . ' Platzhaltern.');
        if (! $this->option('apply')) {
            $this->comment('Trockenlauf — nichts geändert.');

            return self::SUCCESS;
        }
        if (! $this->backupOk()) {
            return self::FAILURE;
        }

        $vorher = [];
        foreach ($plan as $gp) {
            $roh = (array) DB::table('foodalchemist_gps')->where('id', $gp->id)->first();
            $vorher[(int) $gp->id] = array_intersect_key($roh, array_flip([...self::SPALTEN, ...array_map(fn ($f) => "allergen_{$f}", FoodAlchemistGp::ALLERGEN_FIELDS)]));
        }
        $protokoll = $bericht . '/' . now()->format('Ymd_His') . '_platzhalter_deklaration_protokoll.json';
        file_put_contents($protokoll, json_encode(['vorher' => $vorher, 'zeit' => now()->toIso8601String()]));

        foreach ($plan as $gp) {
            $dienst->setzen(Team::findOrFail($gp->team_id), (int) $gp->id, self::VORBELEGUNG[$gp->main_ingredient_slug], rezepteRechnen: false);
        }
        $n = $leads->recomputeNutzerFuerGps(array_map(fn ($gp) => (int) $gp->id, $plan));
        $this->info('Vorbelegt: ' . count($plan) . " Platzhalter, {$n} Rezepte neu gerechnet. Protokoll: {$protokoll}");

        return self::SUCCESS;
    }

    /** Irgendeine Hand-Angabe vorhanden (Allergen-Override, Zusatzstoffe, Nährwerte)? */
    private function hatHandwerte(FoodAlchemistGp $gp): bool
    {
        return $gp->allergenOverrides() !== [] || PlatzhalterDeklarationService::zusatzstoffeManuell($gp) !== [] || $gp->nutri_source !== null;
    }

    private function backupOk(): bool
    {
        $b = (string) $this->option('backup-nachweis');
        if ($b === '' || ! is_file($b) || filesize($b) < 1024) {
            $this->error('--apply braucht --backup-nachweis=<Dump-Datei> (existiert, nicht leer).');

            return false;
        }
        if (filemtime($b) < now()->subDay()->getTimestamp()) {
            $this->error('Backup ist älter als 24 h — bitte frisch ziehen.');

            return false;
        }

        return true;
    }
}
