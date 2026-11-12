<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DefaultRowCache;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ExpressionDescription;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MergePolicy;
use Sabatier\CoreData\NullRowCache;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCache;
use Sabatier\CoreData\PersistentStoreSnapshotProvider;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\SensitiveValue;
use Sabatier\Foundation\Set;

/**
 * @property string $login
 * @property string|null $secret
 * @property Set<SDNote> $notes
 */
final class SDAccount extends ManagedObject
{
}

/**
 * @property string $text
 * @property SDAccount|null $account
 */
final class SDNote extends ManagedObject
{
}

/**
 * A dictionary result hands an isSensitive attribute back masked in a SensitiveValue, at every
 * nesting level, while the framework's own dictionary reads — faults and optimistic-locking
 * snapshots — keep reading it in clear, since they feed it back into objects.
 *
 * The row cache is disabled so that every fault really goes back to the store.
 */
final class SensitiveDictionaryTest extends SQLMigrationTestCase
{
    /** @var class-string<PersistentStoreCache> */
    private string $rowCacheClass;

    private static function model(): ManagedObjectModel
    {
        $login = new AttributeDescription();
        $login->name = "login";
        $login->type = AttributeType::string;

        $secret = new AttributeDescription();
        $secret->name = "secret";
        $secret->type = AttributeType::string;
        $secret->isOptional = true;
        $secret->isSensitive = true;

        $notes = new RelationshipDescription();
        $notes->name = "notes";
        $notes->lazyDestinationEntityName = "SDNote";
        $notes->lazyInverseRelationshipName = "account";
        $notes->isToMany = true;

        $account = new EntityDescription();
        $account->name = "SDAccount";
        $account->managedObjectClassName = SDAccount::class;
        $account->properties = new ArrayClass([$login, $secret, $notes]);

        $text = new AttributeDescription();
        $text->name = "text";
        $text->type = AttributeType::string;

        $accountRelationship = new RelationshipDescription();
        $accountRelationship->name = "account";
        $accountRelationship->lazyDestinationEntityName = "SDAccount";
        $accountRelationship->lazyInverseRelationshipName = "notes";
        $accountRelationship->isOptional = true;

        $note = new EntityDescription();
        $note->name = "SDNote";
        $note->managedObjectClassName = SDNote::class;
        $note->properties = new ArrayClass([$text, $accountRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$account, $note]);
        return $model;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->rowCacheClass = PersistentStore::$rowCacheClass;
        PersistentStore::$rowCacheClass = NullRowCache::class;
        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        PersistentStore::$rowCacheClass = $this->rowCacheClass;
    }

    /** @throws Exception */
    private function seed(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::model());
        $account = new SDAccount($context);
        $account->login = "ana";
        $account->secret = "ana-hash";
        $note = new SDNote($context);
        $note->text = "Hello";
        $note->account = $account;
        $context->save();
        return $this->freshContext(self::model());
    }

    /**
     * @param ArrayClass<string> $propertiesToFetch
     * @return Dictionary<mixed>
     * @throws Exception
     */
    private function dictionary(ManagedObjectContext $context, string $entityClass, ArrayClass $propertiesToFetch): Dictionary
    {
        /** @var class-string<ManagedObject> $entityClass */
        $request = $entityClass::fetchRequest();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToFetch = $propertiesToFetch;
        /** @var Dictionary<mixed> $row */
        $row = $context->fetch($request)->first;
        return $row;
    }

    /** @throws Exception */
    private function account(ManagedObjectContext $context): SDAccount
    {
        /** @var SDAccount $account */
        $account = $context->fetch(SDAccount::fetchRequest())->first;
        return $account;
    }

    /** @throws Exception */
    public function testADictionaryMasksASensitiveAttribute(): void
    {
        $row = $this->dictionary($this->seed(), SDAccount::class, new ArrayClass(["login", "secret"]));

        $this->assertSame("ana", $row["login"]);
        $this->assertInstanceOf(SensitiveValue::class, $row["secret"]);
        $this->assertStringNotContainsString("ana-hash", (string)json_encode($row));
    }

    /** @throws Exception */
    public function testADictionaryMasksASensitiveAttributeOfARelationship(): void
    {
        $row = $this->dictionary($this->seed(), SDNote::class, new ArrayClass(["text", "account"]));
        /** @var Dictionary<mixed> $account */
        $account = $row["account"];

        $this->assertSame("ana", $account["login"]);
        $this->assertInstanceOf(SensitiveValue::class, $account["secret"]);
        $this->assertStringNotContainsString("ana-hash", (string)json_encode($row));
    }

    /** @throws Exception */
    public function testAnObjectStillLoadsItsSensitiveAttribute(): void
    {
        $this->assertSame("ana-hash", $this->account($this->seed())->secret);
    }

    /** @throws Exception */
    public function testARelatedObjectStillLoadsItsSensitiveAttribute(): void
    {
        /** @var SDNote $note */
        $note = $this->seed()->fetch(SDNote::fetchRequest())->first;

        $this->assertSame("ana-hash", $note->account?->secret);
    }

    /**
     * Fetching bare identifiers leaves nothing to fill the object with, so reading it fires a fault
     * that goes back to the store through a dictionary read of its own.
     * @throws Exception
     */
    public function testAFiredFaultLoadsItsSensitiveAttribute(): void
    {
        $context = $this->seed();
        $request = SDAccount::fetchRequest();
        $request->resultType = FetchRequestResultType::managedObjectIDResultType;
        $request->includesPropertyValues = false;
        /** @var ManagedObjectID $objectID */
        $objectID = $context->fetch($request)->first;
        /** @var SDAccount $account */
        $account = $context->object($objectID);

        $this->assertSame("ana-hash", $account->secret);
    }

    /**
     * The optimistic-locking snapshot is a dictionary read, and the store-trump merge writes its
     * values back into the object: a masked value there would reach coercion, which refuses it.
     * @throws Exception
     */
    public function testAStoreTrumpMergeAppliesTheStoredSensitiveValue(): void
    {
        $this->seed();
        $winner = $this->freshContext(self::model());
        $loser = $this->freshContext(self::model());
        $loser->mergePolicy = MergePolicy::mergeByPropertyStoreTrump();
        $stale = $this->account($loser);
        $stale->secret;

        $this->account($winner)->secret = "new-hash";
        $winner->save();
        $stale->login = "ana-renamed";
        $loser->save();

        $this->assertSame("new-hash", $stale->secret);
    }

    /**
     * The deny delete rule reads the stored row together with a count over a to-many; the count only
     * exists in a dictionary, the row's sensitive attribute has to stay in clear.
     * @throws Exception
     */
    public function testASnapshotWithExpressionsKeepsTheRowInClearAndAddsTheExpression(): void
    {
        $context = $this->seed();
        $account = $this->account($context);
        $count = new ExpressionDescription();
        $count->entity = $account->entity;
        $count->name = "computedValue";
        $count->expression = Expression::expressionWithFormat("%K", new ArrayClass(["notes.@count"]));
        $count->resultType = AttributeType::integer32;

        $snapshot = new PersistentStoreSnapshotProvider($context)->snapshotWithExpressions($account, new ArrayClass(["login", "secret"]), new ArrayClass([$count]));

        $this->assertNotNull($snapshot);
        $this->assertSame("ana-hash", $snapshot["secret"]);
        $this->assertSame(1, $snapshot["computedValue"]);
    }

    /**
     * A cached snapshot that covers only part of the row sends the rest through a batch fault.
     * @throws Exception
     */
    public function testABatchFaultLoadsItsSensitiveAttribute(): void
    {
        PersistentStore::$rowCacheClass = DefaultRowCache::class;
        $context = $this->seed();
        $partial = SDAccount::fetchRequest();
        $partial->propertiesToFetch = new ArrayClass(["login"]);
        $context->fetch($partial);
        $sibling = new ManagedObjectContext();
        $sibling->persistentStoreCoordinator = $context->persistentStoreCoordinator;

        $this->assertSame("ana-hash", $this->account($sibling)->secret);
    }
}
