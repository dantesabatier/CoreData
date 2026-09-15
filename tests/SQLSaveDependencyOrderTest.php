<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\SQLCore;
use Sabatier\CoreData\SQLDebugLevel;
use Sabatier\Foundation\ArrayClass;

/**
 * @property string $name
 * @property SQLDepZone|null $zone
 */
final class SQLDepAsset extends ManagedObject
{
}

/**
 * @property string $name
 */
final class SQLDepZone extends ManagedObject
{
}

/**
 * An insert must write the row a foreign key points at BEFORE the row that carries the key.
 *
 * This is why the save groups its objects at all, and it is a constraint rather than a
 * preference: the other order asks the server to store a reference to a row that does not exist
 * yet. The sibling suite covers the other half of the ordering contract — that the order does
 * not vary with the request's mutation order, which is what makes concurrent saves deadlock.
 *
 * The fixture is named so the two rules disagree. "SQLDepAsset" sorts before "SQLDepZone"
 * alphabetically, while the foreign key demands the opposite, so an implementation that resolves
 * the pair by name alone emits them backwards. That matters because dependency is transitive and
 * a comparator is not: usort only compares pairs, so an unrelated entity sorting between two
 * related ones means the pair that matters is never compared and the tie-break decides it.
 *
 * Reuses SQLMigrationTestCase for its database lifecycle only; no migration is exercised.
 */
final class SQLSaveDependencyOrderTest extends SQLMigrationTestCase
{
    /** An Asset pointing at a Zone, named so alphabetical order contradicts the dependency. */
    private static function makeModel(): ManagedObjectModel
    {
        $assetName = new AttributeDescription();
        $assetName->name = "name";
        $assetName->type = AttributeType::string;

        $zone = new RelationshipDescription();
        $zone->name = "zone";
        $zone->lazyDestinationEntityName = "SQLDepZone";
        $zone->lazyInverseRelationshipName = "assets";

        $asset = new EntityDescription();
        $asset->name = "SQLDepAsset";
        $asset->managedObjectClassName = SQLDepAsset::class;
        $asset->properties = new ArrayClass([$assetName, $zone]);

        $zoneName = new AttributeDescription();
        $zoneName->name = "name";
        $zoneName->type = AttributeType::string;

        $assets = new RelationshipDescription();
        $assets->name = "assets";
        $assets->lazyDestinationEntityName = "SQLDepAsset";
        $assets->lazyInverseRelationshipName = "zone";
        $assets->isToMany = true;

        $zoneEntity = new EntityDescription();
        $zoneEntity->name = "SQLDepZone";
        $zoneEntity->managedObjectClassName = SQLDepZone::class;
        $zoneEntity->properties = new ArrayClass([$zoneName, $assets]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$asset, $zoneEntity]);
        return $model;
    }

    /** The SQL the store logged while $body ran. */
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
            ini_set("log_errors", $previousLogErrors === false ? "" : $previousLogErrors);
        }
        $log = file_get_contents($logPath) ?: "";
        unlink($logPath);
        return $log;
    }

    /**
     * The entity names of the INSERT statements the save emitted, in the order it emitted them.
     *
     * @return list<string>
     */
    private static function insertOrder(string $log): array
    {
        $order = [];
        if (preg_match_all("/INSERT INTO `(SQLDep\w+)`/", $log, $matches)) {
            foreach ($matches[1] as $name) {
                if (!in_array($name, $order, true)) {
                    $order[] = $name;
                }
            }
        }
        return $order;
    }

    /**
     * The referenced row is written first, even though its entity name sorts second.
     *
     * @throws Exception
     */
    public function testTheReferencedRowIsInsertedBeforeTheRowThatPointsAtIt(): void
    {
        $context = $this->bootstrap(self::makeModel());

        $zone = new SQLDepZone($context);
        $zone->name = "zone";
        $asset = new SQLDepAsset($context);
        $asset->name = "asset";
        $asset->zone = $zone;

        $order = self::insertOrder($this->sqlDuring(function () use ($context): void {
            $context->save();
        }));

        $this->assertContains("SQLDepZone", $order, "the save emitted no INSERT for the referenced entity");
        $this->assertContains("SQLDepAsset", $order, "the save emitted no INSERT for the referencing entity");
        $this->assertLessThan(
            array_search("SQLDepAsset", $order, true),
            array_search("SQLDepZone", $order, true),
            "the row carrying the foreign key was written before the row it points at",
        );
    }

    /**
     * And it stays first whichever object the request created first — the dependency decides,
     * not the mutation order.
     *
     * @throws Exception
     */
    public function testTheDependencyOrderDoesNotVaryWithTheMutationOrder(): void
    {
        $model = self::makeModel();
        $this->bootstrap($model);

        $assetFirst = $this->insertOrderFor($model, assetFirst: true);
        $zoneFirst = $this->insertOrderFor($model, assetFirst: false);

        $this->assertSame(["SQLDepZone", "SQLDepAsset"], $assetFirst, "creating the asset first must not put it first");
        $this->assertSame($assetFirst, $zoneFirst, "the emitted order varied with the order the request touched the objects");
    }

    /**
     * Creates one asset and one zone in the given order and returns the emitted INSERT order.
     *
     * @return list<string>
     * @throws Exception
     */
    private function insertOrderFor(ManagedObjectModel $model, bool $assetFirst): array
    {
        $context = $this->freshContext($model);
        if ($assetFirst) {
            $asset = new SQLDepAsset($context);
            $asset->name = "asset";
            $zone = new SQLDepZone($context);
            $zone->name = "zone";
        } else {
            $zone = new SQLDepZone($context);
            $zone->name = "zone";
            $asset = new SQLDepAsset($context);
            $asset->name = "asset";
        }
        $asset->zone = $zone;

        return self::insertOrder($this->sqlDuring(function () use ($context): void {
            $context->save();
        }));
    }
}
