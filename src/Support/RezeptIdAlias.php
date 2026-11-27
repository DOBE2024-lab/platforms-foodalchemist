<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Plan 98 · 3.5 Schritt 4: Die Spalte `sales_recipe_id` heißt jetzt `recipe_id`. Die MCP-Tools nehmen den alten Namen bis
 * vier Wochen nach dem Deploy weiter an und geben beide Namen aus (gleiche Bedeutung); danach entfällt der Alias mit
 * Ankündigung. Eine Stelle für beide Richtungen, damit das Entfernen ein einziger Schnitt ist.
 */
final class RezeptIdAlias
{
    public const ALT = 'sales_recipe_id';

    public const NEU = 'recipe_id';

    /** Hinweis für Eingabe-Schemata der Tools. */
    public const BESCHREIBUNG = 'Veraltet: alter Name von recipe_id (gleiche Bedeutung), wird bis 4 Wochen nach dem Rename-Deploy angenommen.';

    /**
     * Tool-Eingabe: `sales_recipe_id` → `recipe_id`, auch verschachtelt (items, felder). Ist `recipe_id` schon gesetzt,
     * gilt es; der alte Schlüssel fällt dann weg.
     */
    public static function eingabe(array $arguments): array
    {
        $aus = [];
        foreach ($arguments as $k => $v) {
            if (is_array($v)) {
                $v = self::eingabe($v);
            }
            if ($k === self::ALT) {
                if (! array_key_exists(self::NEU, $arguments)) {
                    $aus[self::NEU] = $v;
                }

                continue;
            }
            $aus[$k] = $v;
        }

        return $aus;
    }

    /** Tool-Ausgabe: beide Namen mit demselben Wert. */
    public static function beide(mixed $wert): array
    {
        return [self::NEU => $wert, self::ALT => $wert];
    }

    /** Tool-Ausgabe, rekursiv: wo `recipe_id` steht, kommt `sales_recipe_id` mit demselben Wert dazu (für GET-Tools). */
    public static function ausgabe(mixed $daten): mixed
    {
        if ($daten instanceof \Illuminate\Contracts\Support\Arrayable) {
            $daten = $daten->toArray();
        }
        if (! is_array($daten)) {
            return $daten;
        }
        $aus = [];
        foreach ($daten as $k => $v) {
            $aus[$k] = self::ausgabe($v);
            if ($k === self::NEU && ! array_key_exists(self::ALT, $daten)) {
                $aus[self::ALT] = $aus[$k];
            }
        }

        return $aus;
    }

    /** Eingabe-Schema-Eigenschaft für den alten Namen. */
    public static function schema(): array
    {
        return ['type' => 'integer', 'description' => self::BESCHREIBUNG];
    }
}
