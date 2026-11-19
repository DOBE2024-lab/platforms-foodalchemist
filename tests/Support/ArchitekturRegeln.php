<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Welle 1, Paket 1.3a — Architektur-Regeln für ArchitekturRegelnTest (Sperrklinke).
 *
 * Parser-basiert (nikic/php-parser), damit `$service->update()` nicht als Schreibzugriff zählt.
 * Siehe Kopfkommentar in tests/Unit/ArchitekturRegelnTest.php für Regeln, Grenzen und Baseline-Pflege.
 */
final class ArchitekturRegeln
{
    public const LIVEWIRE_NS = 'Platform\\FoodAlchemist\\Livewire\\';

    /** Regel => Verzeichnisse (relativ zur Modulwurzel), in denen sie gilt. */
    public const GELTUNG = [
        'livewire_import' => ['src/Services', 'src/Tools'],
        'schreibzugriff' => ['src/Livewire', 'src/Tools'],
    ];

    /** Methoden, die auf einem Eloquent-Empfänger (Model, Builder, Relation, Collection) schreiben. */
    public const SCHREIB_METHODEN = [
        'save', 'saveQuietly', 'saveMany', 'push', 'pushQuietly', 'touch', 'touchQuietly',
        'create', 'createQuietly', 'createMany', 'createManyQuietly', 'forceCreate', 'forceCreateQuietly',
        'update', 'updateQuietly', 'updateOrCreate', 'updateOrInsert', 'firstOrCreate', 'createOrFirst',
        'insert', 'insertOrIgnore', 'insertGetId', 'insertUsing', 'upsert',
        'delete', 'deleteQuietly', 'forceDelete', 'forceDeleteQuietly', 'destroy', 'truncate', 'restore', 'restoreQuietly',
        'increment', 'decrement', 'incrementQuietly', 'decrementQuietly', 'incrementEach', 'decrementEach',
        'attach', 'detach', 'sync', 'syncWithoutDetaching', 'syncWithPivotValues', 'toggle', 'updateExistingPivot',
    ];

    /** Direkte Schreibbefehle über die DB-Fassade. */
    public const DB_SCHREIB_STATISCH = ['insert', 'update', 'delete', 'statement', 'affectingStatement', 'unprepared'];

    /** Collection-Methoden, deren Closure-Parameter das Element ist (untypisiert erlaubt). */
    private const ELEMENT_CLOSURES = ['each', 'map', 'filter', 'reject', 'transform', 'mapWithKeys', 'flatMap', 'every', 'first', 'sortBy', 'groupBy', 'keyBy', 'chunk', 'chunkById', 'lazy', 'tap'];

    private const ELOQUENT_BASEN = [Model::class, EloquentBuilder::class, QueryBuilder::class, Relation::class, EloquentCollection::class];

    private const DB_FASSADEN = ['Illuminate\\Support\\Facades\\DB', 'DB'];

    /** Typ-Marke für „irgendein Eloquent-Wert“, wenn die genaue Klasse egal ist. */
    private const ELOQUENT = '@eloquent';

    /** @var array<string, array<string, list<string>>>|null pfad => regel => funde */
    private static ?array $cache = null;

    /** @var array<string, bool> */
    private static array $eloquentKlasse = [];

    /** @return array<string, array<string, int>> */
    public static function zaehlen(): array
    {
        $ergebnis = [];
        foreach (self::alleFunde() as $pfad => $regeln) {
            foreach ($regeln as $regel => $funde) {
                if ($funde !== []) {
                    $ergebnis[$pfad][$regel] = count($funde);
                }
            }
        }
        ksort($ergebnis);

        return $ergebnis;
    }

    /** @return list<string> Fundstellen einer Datei, für Fehlermeldungen */
    public static function funde(string $pfad): array
    {
        $zeilen = [];
        foreach (self::alleFunde()[$pfad] ?? [] as $regel => $funde) {
            foreach ($funde as $fund) {
                $zeilen[] = "{$regel} {$fund}";
            }
        }

        return $zeilen;
    }

    public static function baselinePfad(): string
    {
        return __DIR__ . '/../Fixtures/architektur_regeln_baseline.json';
    }

    /** @return list<string> "Zeile N: Name" je Bezug auf Livewire */
    public static function livewireImporte(string $code): array
    {
        $ast = self::parsen($code);
        $funde = [];
        foreach ((new NodeFinder())->find($ast, fn (Node $n) => $n instanceof Stmt\Use_ || $n instanceof Stmt\GroupUse || $n instanceof Node\Name) as $n) {
            if ($n instanceof Stmt\Use_ && $n->type === Stmt\Use_::TYPE_NORMAL) {
                foreach ($n->uses as $use) {
                    self::livewireFund($funde, $use->name->toString(), $use->getStartLine());
                }
            } elseif ($n instanceof Stmt\GroupUse) {
                foreach ($n->uses as $use) {
                    self::livewireFund($funde, $n->prefix->toString() . '\\' . $use->name->toString(), $use->getStartLine());
                }
            } elseif ($n instanceof Node\Name\FullyQualified) {
                // Nach dem NameResolver sind alle Verwendungen voll qualifiziert (use-Namen bleiben Name, nicht FullyQualified).
                self::livewireFund($funde, $n->toString(), $n->getStartLine());
            }
        }

        return $funde;
    }

    /** @return list<string> "Zeile N: beschreibung" je Schreibzugriff auf Eloquent */
    public static function schreibzugriffe(string $code): array
    {
        $ast = self::parsen($code);
        $finder = new NodeFinder();
        $funde = [];

        foreach ($finder->findInstanceOf($ast, Stmt\ClassLike::class) as $klasse) {
            $name = $klasse->namespacedName?->toString();
            $eigenschaften = [];
            $rueckgaben = [];
            foreach ($klasse->getProperties() as $prop) {
                if (($typ = self::typName($prop->type, $name)) !== null) {
                    foreach ($prop->props as $p) {
                        $eigenschaften[$p->name->toString()] = $typ;
                    }
                }
            }
            foreach ($klasse->getMethods() as $methode) {
                if ($methode->name->toString() === '__construct') {
                    foreach ($methode->params as $param) {
                        if ($param->flags !== 0 && $param->var instanceof Expr\Variable && is_string($param->var->name)
                            && ($typ = self::typName($param->type, $name)) !== null) {
                            $eigenschaften[$param->var->name] = $typ; // Constructor-Promotion
                        }
                    }
                }
                if (($typ = self::typName($methode->returnType, $name)) !== null) {
                    $rueckgaben[$methode->name->toLowerString()] = $typ;
                }
            }
            foreach ($klasse->getMethods() as $methode) {
                $ctx = self::kontext($name, $eigenschaften, $rueckgaben);
                self::sammleVariablen($methode, $ctx);
                self::sammleSchreibzugriffe($methode->stmts ?? [], $ctx, $funde);
            }
        }

        // Code außerhalb von Klassen (Funktionen) — selten, der Vollständigkeit halber.
        foreach ($finder->findInstanceOf($ast, Stmt\Function_::class) as $funktion) {
            $ctx = self::kontext(null, [], []);
            self::sammleVariablen($funktion, $ctx);
            self::sammleSchreibzugriffe($funktion->stmts ?? [], $ctx, $funde);
        }

        sort($funde, SORT_NATURAL);

        return $funde;
    }

    // ── intern ───────────────────────────────────────────────────────────────

    /** @return array<string, array<string, list<string>>> */
    private static function alleFunde(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $wurzel = realpath(__DIR__ . '/../..');
        $ergebnis = [];
        $verzeichnisse = array_unique(array_merge(...array_values(self::GELTUNG)));
        foreach ($verzeichnisse as $verzeichnis) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel . '/' . $verzeichnis, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $datei) {
                if (! str_ends_with($datei->getFilename(), '.php')) {
                    continue;
                }
                $pfad = str_replace($wurzel . '/', '', $datei->getPathname());
                $code = file_get_contents($datei->getPathname());
                foreach (self::GELTUNG as $regel => $orte) {
                    if (! self::liegtIn($pfad, $orte) || isset($ergebnis[$pfad][$regel])) {
                        continue;
                    }
                    $ergebnis[$pfad][$regel] = $regel === 'livewire_import' ? self::livewireImporte($code) : self::schreibzugriffe($code);
                }
            }
        }
        ksort($ergebnis);

        return self::$cache = $ergebnis;
    }

    private static function liegtIn(string $pfad, array $orte): bool
    {
        foreach ($orte as $ort) {
            if (str_starts_with($pfad, $ort . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return Node[] */
    private static function parsen(string $code): array
    {
        $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser(new NameResolver());

        return $traverser->traverse($ast);
    }

    private static function livewireFund(array &$funde, string $name, int $zeile): void
    {
        if (str_starts_with(ltrim($name, '\\'), self::LIVEWIRE_NS)) {
            $funde[] = "Zeile {$zeile}: " . ltrim($name, '\\');
        }
    }

    /** Kontext je Methode: Klassenname, typisierte Eigenschaften/Rückgaben der Klasse, abgeleitete Variablentypen. */
    private static function kontext(?string $klasse, array $eigenschaften, array $rueckgaben): object
    {
        return new class ($klasse, $eigenschaften, $rueckgaben)
        {
            /** @var array<string, string> variable => Klasse oder ELOQUENT */
            public array $vars = [];

            public function __construct(public ?string $klasse, public array $eigenschaften, public array $rueckgaben) {}
        };
    }

    /** Variablentypen einer Methode — flussunabhängig (inkl. Closures), bis nichts mehr hinzukommt. */
    private static function sammleVariablen(Node\FunctionLike $funktion, object $ctx): void
    {
        $finder = new NodeFinder();
        $alle = array_merge([$funktion], $finder->find($funktion->getStmts() ?? [], fn (Node $n) => $n instanceof Node\FunctionLike));
        foreach ($alle as $f) {
            foreach ($f->getParams() as $param) {
                if ($param->var instanceof Expr\Variable && is_string($param->var->name)
                    && ($typ = self::typName($param->type, $ctx->klasse)) !== null) {
                    $ctx->vars[$param->var->name] = $typ;
                }
            }
        }

        $knoten = $finder->find($funktion->getStmts() ?? [], fn (Node $n) => $n instanceof Expr\Assign
            || $n instanceof Expr\AssignRef
            || $n instanceof Stmt\Foreach_
            || $n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall);

        do {
            $vorher = count($ctx->vars);
            foreach ($knoten as $n) {
                if ($n instanceof Expr\Assign || $n instanceof Expr\AssignRef) {
                    self::markiere($n->var, self::typVon($n->expr, $ctx), $ctx);
                } elseif ($n instanceof Stmt\Foreach_ && self::istEloquent($n->expr, $ctx)) {
                    self::markiere($n->valueVar, self::ELOQUENT, $ctx);
                } elseif (($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall)
                    && $n->name instanceof Node\Identifier
                    && in_array($n->name->toString(), self::ELEMENT_CLOSURES, true)
                    && self::istEloquent($n->var, $ctx)) {
                    foreach ($n->getArgs() as $arg) {
                        if (($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) && isset($arg->value->params[0])) {
                            self::markiere($arg->value->params[0]->var, self::ELOQUENT, $ctx);
                        }
                    }
                }
            }
        } while (count($ctx->vars) > $vorher);
    }

    private static function markiere(Node $ziel, ?string $typ, object $ctx): void
    {
        if ($typ === null) {
            return;
        }
        if ($ziel instanceof Expr\Variable && is_string($ziel->name) && ! isset($ctx->vars[$ziel->name])) {
            $ctx->vars[$ziel->name] = $typ;
        } elseif ($ziel instanceof Expr\List_ && $typ === self::ELOQUENT) {
            foreach ($ziel->items as $item) {
                if ($item !== null) {
                    self::markiere($item->value, $typ, $ctx);
                }
            }
        }
    }

    private static function sammleSchreibzugriffe(array $stmts, object $ctx, array &$funde): void
    {
        $aufrufe = (new NodeFinder())->find($stmts, fn (Node $n) => $n instanceof Expr\MethodCall
            || $n instanceof Expr\NullsafeMethodCall
            || $n instanceof Expr\StaticCall);

        foreach ($aufrufe as $aufruf) {
            if (! $aufruf->name instanceof Node\Identifier) {
                continue;
            }
            $methode = $aufruf->name->toString();

            if ($aufruf instanceof Expr\StaticCall) {
                if (! $aufruf->class instanceof Node\Name) {
                    continue;
                }
                $klasse = $aufruf->class->toString();
                if (in_array($klasse, self::DB_FASSADEN, true) && in_array($methode, self::DB_SCHREIB_STATISCH, true)) {
                    $funde[] = "Zeile {$aufruf->getStartLine()}: DB::{$methode}()";
                } elseif (in_array($methode, self::SCHREIB_METHODEN, true) && self::klasseIstEloquent($klasse)) {
                    $funde[] = "Zeile {$aufruf->getStartLine()}: " . self::kurz($klasse) . "::{$methode}()";
                }

                continue;
            }

            if (in_array($methode, self::SCHREIB_METHODEN, true) && self::istEloquent($aufruf->var, $ctx)) {
                $funde[] = "Zeile {$aufruf->getStartLine()}: …->{$methode}()";
            }
        }
    }

    /** Liefert der Ausdruck ein Model, einen Builder, eine Relation oder eine Model-Collection? */
    private static function istEloquent(Expr $e, object $ctx): bool
    {
        $typ = self::typVon($e, $ctx);

        return $typ !== null && ($typ === self::ELOQUENT || self::klasseIstEloquent($typ));
    }

    /**
     * Statischer Typ eines Ausdrucks, soweit ohne Typ-Inferenz-Werkzeug sicher ableitbar:
     * Klassenname, ELOQUENT (irgendein Eloquent-Wert) oder null (unbekannt → zählt nicht).
     */
    private static function typVon(Expr $e, object $ctx): ?string
    {
        switch (true) {
            case $e instanceof Expr\Variable:
                if ($e->name === 'this') {
                    return $ctx->klasse;
                }

                return is_string($e->name) ? ($ctx->vars[$e->name] ?? null) : null;

            case $e instanceof Expr\New_:
                return $e->class instanceof Node\Name ? self::aufloesen($e->class->toString(), $ctx->klasse) : null;

            case $e instanceof Expr\FuncCall:
                // app(X::class) / resolve(X::class)
                if ($e->name instanceof Node\Name && in_array($e->name->toLowerString(), ['app', 'resolve'], true)
                    && ($arg = $e->getArgs()[0] ?? null) && $arg->value instanceof Expr\ClassConstFetch
                    && $arg->value->class instanceof Node\Name && $arg->value->name instanceof Node\Identifier
                    && $arg->value->name->toLowerString() === 'class') {
                    return self::aufloesen($arg->value->class->toString(), $ctx->klasse);
                }

                return null;

            case $e instanceof Expr\StaticCall:
                if (! $e->class instanceof Node\Name || ! $e->name instanceof Node\Identifier) {
                    return null;
                }
                $klasse = self::aufloesen($e->class->toString(), $ctx->klasse);
                if ($klasse === null) {
                    return null;
                }
                if (self::klasseIstEloquent($klasse)) {
                    return self::ELOQUENT;
                }
                if (in_array($klasse, self::DB_FASSADEN, true)) {
                    return in_array($e->name->toString(), ['table', 'connection', 'query'], true) ? self::ELOQUENT : null;
                }

                return self::rueckgabeTyp($klasse, $e->name->toString());

            case $e instanceof Expr\MethodCall:
            case $e instanceof Expr\NullsafeMethodCall:
                if (! $e->name instanceof Node\Identifier) {
                    return null;
                }
                if ($e->var instanceof Expr\Variable && $e->var->name === 'this' && isset($ctx->rueckgaben[$e->name->toLowerString()])) {
                    return $ctx->rueckgaben[$e->name->toLowerString()];
                }
                $basis = self::typVon($e->var, $ctx);
                if ($basis === null) {
                    return null;
                }
                if ($basis === self::ELOQUENT || self::klasseIstEloquent($basis)) {
                    return self::ELOQUENT; // Ketten auf Eloquent bleiben Eloquent (where, first, relation() …)
                }

                return self::rueckgabeTyp($basis, $e->name->toString());

            case $e instanceof Expr\PropertyFetch:
            case $e instanceof Expr\NullsafePropertyFetch:
                if (! $e->name instanceof Node\Identifier) {
                    return null;
                }
                if ($e->var instanceof Expr\Variable && $e->var->name === 'this' && isset($ctx->eigenschaften[$e->name->toString()])) {
                    return $ctx->eigenschaften[$e->name->toString()];
                }
                $basis = self::typVon($e->var, $ctx);
                if ($basis === null) {
                    return null;
                }
                if ($basis === self::ELOQUENT || self::klasseIstEloquent($basis)) {
                    return self::ELOQUENT; // Relation/Attribut eines Models
                }

                return self::eigenschaftTyp($basis, $e->name->toString());

            case $e instanceof Expr\ArrayDimFetch:
                return self::istEloquent($e->var, $ctx) ? self::ELOQUENT : null;

            case $e instanceof Expr\Assign:
                return self::typVon($e->expr, $ctx);

            case $e instanceof Expr\Ternary:
                return ($e->if !== null ? self::typVon($e->if, $ctx) : null) ?? self::typVon($e->else, $ctx);

            case $e instanceof Expr\BinaryOp\Coalesce:
                return self::typVon($e->left, $ctx) ?? self::typVon($e->right, $ctx);
        }

        return null;
    }

    /** Erste Klasse aus einer Typangabe (nullable/union), self/static aufgelöst. */
    private static function typName(?Node $typ, ?string $klasse): ?string
    {
        if ($typ instanceof Node\NullableType) {
            return self::typName($typ->type, $klasse);
        }
        if ($typ instanceof Node\UnionType || $typ instanceof Node\IntersectionType) {
            $namen = array_values(array_filter(array_map(fn ($t) => self::typName($t, $klasse), $typ->types)));
            foreach ($namen as $n) {
                if (self::klasseIstEloquent($n)) {
                    return $n;
                }
            }

            return $namen[0] ?? null;
        }

        return $typ instanceof Node\Name ? self::aufloesen($typ->toString(), $klasse) : null;
    }

    private static function aufloesen(string $name, ?string $klasse): ?string
    {
        $name = ltrim($name, '\\');

        return match (strtolower($name)) {
            'self', 'static' => $klasse,
            'parent' => $klasse !== null && class_exists($klasse) ? (get_parent_class($klasse) ?: null) : null,
            default => $name,
        };
    }

    private static function rueckgabeTyp(string $klasse, string $methode): ?string
    {
        try {
            if (! class_exists($klasse) && ! interface_exists($klasse)) {
                return null;
            }
            $ref = new \ReflectionClass($klasse);
            if (! $ref->hasMethod($methode)) {
                return null;
            }

            return self::reflexionsTyp($ref->getMethod($methode)->getReturnType(), $ref->getMethod($methode)->getDeclaringClass()->getName(), $klasse);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function eigenschaftTyp(string $klasse, string $eigenschaft): ?string
    {
        try {
            if (! class_exists($klasse) || ! property_exists($klasse, $eigenschaft)) {
                return null;
            }
            $prop = new \ReflectionProperty($klasse, $eigenschaft);

            return self::reflexionsTyp($prop->getType(), $prop->getDeclaringClass()->getName(), $klasse);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function reflexionsTyp(?\ReflectionType $typ, string $deklariert, string $aufgerufen): ?string
    {
        $typen = $typ instanceof \ReflectionUnionType || $typ instanceof \ReflectionIntersectionType ? $typ->getTypes() : [$typ];
        $namen = [];
        foreach ($typen as $t) {
            if ($t instanceof \ReflectionNamedType && ! $t->isBuiltin()) {
                $namen[] = match (strtolower($t->getName())) {
                    'self' => $deklariert,
                    'static' => $aufgerufen,
                    default => $t->getName(),
                };
            }
        }
        foreach ($namen as $n) {
            if (self::klasseIstEloquent($n)) {
                return $n;
            }
        }

        return $namen[0] ?? null;
    }

    private static function klasseIstEloquent(string $klasse): bool
    {
        $klasse = ltrim($klasse, '\\');
        if (isset(self::$eloquentKlasse[$klasse])) {
            return self::$eloquentKlasse[$klasse];
        }
        $ist = false;
        try {
            if (class_exists($klasse)) {
                foreach (self::ELOQUENT_BASEN as $basis) {
                    if (is_a($klasse, $basis, true)) {
                        $ist = true;
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            $ist = false;
        }

        return self::$eloquentKlasse[$klasse] = $ist;
    }

    private static function kurz(string $klasse): string
    {
        return substr(strrchr('\\' . $klasse, '\\'), 1);
    }
}
