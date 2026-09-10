<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\SQLCore;
use Sabatier\CoreData\SQLDebugLevel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

/**
 * @property string $name
 * @property string|null $note
 */
final class SQLLockOrderAlpha extends ManagedObject
{
}

final class SQLLockOrderOmega extends ManagedObject
{
}

/**
 * A save must emit its statements in an order that depends only on WHAT changed, never on the
 * order the request happened to touch the objects.
 *
 * Two concurrent saves that write the same rows in opposite orders deadlock in InnoDB: each
 * holds a row the other is waiting for. The server picks a victim and rolls it back, and the
 * request dies on whatever statement runs next — in production that surfaced as error 1020
 * against PersistentHistoryTransaction, a table that had nothing to do with the conflict.
 *
 * groupedObjects() sorts by relationship dependency, which orders the pairs it knows about but
 * leaves every unrelated pair equal — and unrelated is the common case, so the emitted table
 * order followed the context's mutation order. Two entities with no relationship between them
 * are exactly that case, so this fixture holds none.
 *
 * Reuses SQLMigrationTestCase for its database lifecycle only; no migration is exercised.
 */
final class SQLSaveLockOrderTest extends SQLMigrationTestCase
{
    /**
     * Two unrelated entities. No relationship joins them, so dependency sorting cannot order
     * them and only the tie-breaker can.
     */
    private static function makeModel(): ManagedObjectModel
    {
        $alphaName = new AttributeDescription();
        $alphaName->name = "name";
        $alphaName->type = AttributeType::string;

        // Dirtied by the intra-table test so "name" stays a stable handle on each row.
        $alphaNote = new AttributeDescription();
        $alphaNote->name = "note";
        $alphaNote->type = AttributeType::string;
        $alphaNote->isOptional = true;

        $alpha = new EntityDescription();
        $alpha->name = "SQLLockOrderAlpha";
        $alpha->managedObjectClassName = SQLLockOrderAlpha::class;
        $alpha->properties = new ArrayClass([$alphaName, $alphaNote]);

        $omegaName = new AttributeDescription();
        $omegaName->name = "name";
        $omegaName->type = AttributeType::string;

        $omega = new EntityDescription();
        $omega->name = "SQLLockOrderOmega";
        $omega->managedObjectClassName = SQLLockOrderOmega::class;
        $omega->properties = new ArrayClass([$omegaName]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$alpha, $omega]);
        return $model;
    }

    /**
     * Runs $body with SQL logging on and returns everything the store logged.
     *
     * The generator has no seam to read the statement order from, so this reads the SQL the
     * store actually sent: debug logging writes each statement through error_log, which is
     * redirected to a temporary file for the duration.
     *
     * @param callable(): void $body
     */
    private function sqlDuring(callable $body): string
    {
        $logPath = tempnam(sys_get_temp_dir(), "coredata-sql-");
        $previousDestination = ini_get("error_log");
        $previousLogErrors = ini_get("log_errors");
        $previousLevel = SQLCore::$debugLevel;

        ini_set("error_log", $logPath);
        ini_set("log_errors", "1");
        SQLCore::$debugLevel = SQLDebugLevel::sqlWithParams;
        try {
            $body();
        } finally {
            SQLCore::$debugLevel = $previousLevel;
            ini_set("error_log", $previousDestination === false ? "" : $previousDestination);
            ini_set("log_errors", $previousLogErrors === false ? "1" : $previousLogErrors);
        }

        $log = (string)file_get_contents($logPath);
        unlink($logPath);
        return $log;
    }

    /**
     * The tables named by the UPDATE statements $body emitted, in order.
     *
     * @param callable(): void $body
     * @return list<string>
     */
    private function updatedTablesDuring(callable $body): array
    {
        $tables = [];
        if (preg_match_all("/UPDATE `([^`]+)`/", $this->sqlDuring($body), $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    /**
     * Dirties one row of each entity — in the given order — and returns the tables the save
     * wrote, in emission order.
     *
     * @return list<string>
     */
    private function updateOrder(ManagedObjectContext $context, bool $omegaFirst, string $suffix): array
    {
        $alpha = $context->fetch(SQLLockOrderAlpha::fetchRequest())->first;
        $omega = $context->fetch(SQLLockOrderOmega::fetchRequest())->first;
        $this->assertNotNull($alpha);
        $this->assertNotNull($omega);

        // The only difference between the two runs: which object the request dirties first.
        if ($omegaFirst) {
            $omega->name = "omega-$suffix";
            $alpha->name = "alpha-$suffix";
        } else {
            $alpha->name = "alpha-$suffix";
            $omega->name = "omega-$suffix";
        }

        return $this->updatedTablesDuring(function () use ($context): void {
            $context->save();
        });
    }

    /**
     * Dirties every Alpha row, touching them in $order, and returns the primary keys in the
     * order the emitted UPDATE bound them.
     *
     * Rows of one entity land in a single UPDATE whose CASE arms carry the keys in group order,
     * so the arms are what reveal the intra-table lock order.
     *
     * @param list<string> $order the names of the rows to dirty, in the order to touch them
     * @return list<string>
     */
    private function updatedKeysForAlphas(ManagedObjectContext $context, array $order, string $suffix): array
    {
        /** @var Dictionary<SQLLockOrderAlpha> $byName */
        $byName = new Dictionary();
        foreach ($context->fetch(SQLLockOrderAlpha::fetchRequest()) as $alpha) {
            $byName[(string)$alpha->name] = $alpha;
        }
        foreach ($order as $name) {
            $alpha = $byName[$name];
            $this->assertNotNull($alpha, "the fixture row \"$name\" is missing");
            $alpha->note = "$name-$suffix";
        }

        $log = $this->sqlDuring(function () use ($context): void {
            $context->save();
        });

        // "WHEN `objectID` = ? THEN ?" binds the key first, so the key arguments appear in the
        // statement's argument list in CASE-arm order.
        $keys = [];
        if (preg_match("/UPDATE `SQLLockOrderAlpha`.*/s", $log, $statement) && preg_match_all("/\bWHEN `objectID` = (\d+)/", $statement[0], $matches)) {
            foreach ($matches[1] as $key) {
                if (!in_array($key, $keys, true)) {
                    $keys[] = $key;
                }
            }
        }
        return $keys;
    }

    /**
     * The regression: the emitted order must be identical whichever object was dirtied first.
     *
     * Before the fix the two runs produced opposite orders, which is the precondition for the
     * production deadlock — two requests racing with mirrored lock sequences.
     */
    public function testUpdateOrderIsIndependentOfMutationOrder(): void
    {
        $model = self::makeModel();
        $context = $this->bootstrap($model);

        $alpha = new SQLLockOrderAlpha($context);
        $alpha->name = "alpha";
        $omega = new SQLLockOrderOmega($context);
        $omega->name = "omega";
        $context->save();

        $alphaFirst = $this->updateOrder($this->freshContext($model), omegaFirst: false, suffix: "a");
        $omegaFirst = $this->updateOrder($this->freshContext($model), omegaFirst: true, suffix: "b");

        $this->assertContains("SQLLockOrderAlpha", $alphaFirst, "the save emitted no UPDATE for the dirtied Alpha row; the log capture is not seeing the statements");
        $this->assertContains("SQLLockOrderOmega", $alphaFirst, "the save emitted no UPDATE for the dirtied Omega row; the log capture is not seeing the statements");
        $this->assertSame($alphaFirst, $omegaFirst, "the save emitted its tables in a different order depending on which object the request touched first; two concurrent saves in mirrored orders deadlock");
    }

    /**
     * The same guarantee one level down: rows WITHIN a table must also be locked in a fixed
     * order, which is what the primary-key tie-break provides.
     *
     * Two saves updating the same rows of one table in opposite orders deadlock exactly like
     * two saves updating two tables in opposite orders; the entity-name tie-break alone does
     * not reach this case, since here every object carries the same entity name.
     */
    public function testUpdateOrderWithinATableIsIndependentOfMutationOrder(): void
    {
        $model = self::makeModel();
        $context = $this->bootstrap($model);

        foreach (["first", "second", "third"] as $name) {
            $alpha = new SQLLockOrderAlpha($context);
            $alpha->name = $name;
        }
        $context->save();

        $forwards = $this->updatedKeysForAlphas($this->freshContext($model), ["first", "second", "third"], "a");
        $backwards = $this->updatedKeysForAlphas($this->freshContext($model), ["third", "second", "first"], "b");

        $this->assertCount(3, $forwards, "the save did not bind all three rows into the UPDATE");
        $this->assertSame($forwards, $backwards, "the save bound its rows in a different order depending on the order the request touched them; two concurrent saves in mirrored orders deadlock");
        $ascending = $forwards;
        usort($ascending, fn(string $e0, string $e1): int => (int)$e0 <=> (int)$e1);
        $this->assertSame($ascending, $forwards, "the rows were not bound in ascending primary-key order");
    }
}
