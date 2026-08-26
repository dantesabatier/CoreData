<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentHistoryChange;
use Sabatier\CoreData\PersistentHistoryChangeType;
use Sabatier\CoreData\PersistentHistoryTransaction;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Number;
use const Sabatier\CoreData\DeletedObjectsKey;
use const Sabatier\CoreData\InsertedObjectsKey;
use const Sabatier\CoreData\UpdatedObjectsKey;
use const Sabatier\Foundation\NotFound;
use const Sabatier\Foundation\UUID_NULL;

/**
 * Tests the value-object behavior of PersistentHistoryTransaction without involving a store.
 *
 * SQLPersistentHistoryChangeRequestContext builds these objects from database dictionaries, but
 * the transaction itself is responsible for normalizing those values, deriving its token,
 * converting its changes into a merge notification, and exposing the history entity to fetches.
 */
final class PersistentHistoryTransactionTest extends TestCase
{
    private ?EntityDescription $originalEntityDescription;

    protected function setUp(): void
    {
        $this->originalEntityDescription = PersistentHistoryTransaction::$entityDescription;
    }

    protected function tearDown(): void
    {
        PersistentHistoryTransaction::$entityDescription = $this->originalEntityDescription;
    }

    private static function entity(string $name): EntityDescription
    {
        $entity = new EntityDescription();
        $entity->name = $name;
        $entity->properties = new ArrayClass();
        return $entity;
    }

    private static function change(EntityDescription $entity, int $changeID, PersistentHistoryChangeType $type): PersistentHistoryChange
    {
        return new PersistentHistoryChange(new Dictionary([
            "changeID" => new Number($changeID),
            "changeType" => new Number($type->value),
        ]), new ManagedObjectID($entity, $changeID));
    }

    public function testAnEmptyDictionaryUsesTheDocumentedDefaults(): void
    {
        $transaction = new PersistentHistoryTransaction(new Dictionary());
        $timestamp = $transaction->timestamp->timeIntervalSince1970;

        $this->assertSame(NotFound, $transaction->transactionNumber);
        $this->assertNull($transaction->author);
        $this->assertNull($transaction->contextName);
        $this->assertNull($transaction->changes);
        $this->assertSame(UUID_NULL, $transaction->bundleID);
        $this->assertSame(UUID_NULL, $transaction->processID);
        $this->assertSame(UUID_NULL, $transaction->storeID);
        $this->assertEqualsWithDelta(Date::now()->timeIntervalSince1970, $timestamp, 0.1, "the default timestamp is created on first access");
    }

    public function testConstructionNormalizesDatabaseValues(): void
    {
        $timestamp = "2025-02-03 04:05:06 UTC";
        $transaction = new PersistentHistoryTransaction(new Dictionary([
            "transactionID" => new Number(17),
            "timestamp" => $timestamp,
            "author" => "history-worker",
            "bundleID" => "com.example.history",
            "contextName" => "background",
            "processID" => "process-42",
            "storeID" => "store-A",
        ]));

        $this->assertSame(17, $transaction->transactionNumber, "transactionID is exposed as transactionNumber");
        $this->assertInstanceOf(Date::class, $transaction->timestamp);
        $this->assertSame((float)strtotime($timestamp), $transaction->timestamp->timeIntervalSince1970);
        $this->assertSame("history-worker", $transaction->author);
        $this->assertSame("com.example.history", $transaction->bundleID);
        $this->assertSame("background", $transaction->contextName);
        $this->assertSame("process-42", $transaction->processID);
        $this->assertSame("store-A", $transaction->storeID);
    }

    public function testTokenIsDerivedFromStoreAndTransactionAndMemoized(): void
    {
        $transaction = new PersistentHistoryTransaction(new Dictionary([
            "transactionID" => 23,
            "storeID" => "store-B",
        ]));

        $token = $transaction->token;

        $this->assertSame($token, $transaction->token, "the lazily-created token is stable");
        $this->assertInstanceOf(Number::class, $token->storeTokens["store-B"]);
        $this->assertSame(23, $token->storeTokens["store-B"]->intValue);
    }

    public function testObjectIDNotificationGroupsChangesByChangeType(): void
    {
        $entity = self::entity("HistoryItem");
        $insert = self::change($entity, 1, PersistentHistoryChangeType::insert);
        $update = self::change($entity, 2, PersistentHistoryChangeType::update);
        $delete = self::change($entity, 3, PersistentHistoryChangeType::delete);
        $transaction = new PersistentHistoryTransaction(new Dictionary([
            "changes" => new ArrayClass([$insert, $update, $delete]),
        ]));

        $notification = $transaction->objectIDNotification();

        $this->assertSame(ManagedObjectContext::didSaveObjectIDsNotification, $notification->name);
        $this->assertNull($notification->object);
        $this->assertNotNull($notification->userInfo);
        $this->assertSame([$insert->changedObjectID], $notification->userInfo[InsertedObjectsKey]->array);
        $this->assertSame([$update->changedObjectID], $notification->userInfo[UpdatedObjectsKey]->array);
        $this->assertSame([$delete->changedObjectID], $notification->userInfo[DeletedObjectsKey]->array);
    }

    public function testObjectIDNotificationHasNoUserInfoWhenChangesAreAbsent(): void
    {
        $notification = new PersistentHistoryTransaction(new Dictionary())->objectIDNotification();

        $this->assertSame(ManagedObjectContext::didSaveObjectIDsNotification, $notification->name);
        $this->assertNull($notification->userInfo);
    }

    public function testAnExplicitEmptyChangeCollectionProducesEmptyMergeSets(): void
    {
        $transaction = new PersistentHistoryTransaction(new Dictionary(["changes" => new ArrayClass()]));

        $userInfo = $transaction->objectIDNotification()->userInfo;

        $this->assertNotNull($userInfo);
        $this->assertSame([], $userInfo[InsertedObjectsKey]->array);
        $this->assertSame([], $userInfo[UpdatedObjectsKey]->array);
        $this->assertSame([], $userInfo[DeletedObjectsKey]->array);
    }

    public function testFetchRequestRequiresTheRegisteredHistoryEntity(): void
    {
        PersistentHistoryTransaction::$entityDescription = null;
        $this->assertNull(PersistentHistoryTransaction::fetchRequest());

        $entity = self::entity("PersistentHistoryTransaction");
        PersistentHistoryTransaction::$entityDescription = $entity;

        $request = PersistentHistoryTransaction::fetchRequest();
        $this->assertNotNull($request);
        $this->assertSame($entity, $request->entity);
        $this->assertSame("PersistentHistoryTransaction", $request->entityName);
        $this->assertNotSame($request, PersistentHistoryTransaction::fetchRequest(), "each call creates an independent request");
    }

    public function testEntityDescriptionIsResolvedFromTheContextsModel(): void
    {
        $context = new ManagedObjectContext();
        $this->assertNull(PersistentHistoryTransaction::entityDescription($context));

        $entity = self::entity("PersistentHistoryTransaction");
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        $context->persistentStoreCoordinator = new PersistentStoreCoordinator($model);

        $this->assertSame($entity, PersistentHistoryTransaction::entityDescription($context));

        $context->persistentStoreCoordinator = null;
    }

    public function testEntityDescriptionIsNullWhenTheContextsModelDoesNotContainIt(): void
    {
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([self::entity("OtherEntity")]);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = new PersistentStoreCoordinator($model);

        $this->assertNull(PersistentHistoryTransaction::entityDescription($context));

        $context->persistentStoreCoordinator = null;
    }

    public function testJsonSerializationIncludesMetadataAndSerializedChanges(): void
    {
        $entity = self::entity("HistoryItem");
        $change = self::change($entity, 8, PersistentHistoryChangeType::insert);
        $transaction = new PersistentHistoryTransaction(new Dictionary([
            "transactionID" => 31,
            "timestamp" => "2025-05-06 07:08:09 UTC",
            "author" => "serializer",
            "bundleID" => "com.example.serializer",
            "contextName" => "writer",
            "processID" => "process-serializer",
            "storeID" => "store-C",
            "changes" => new ArrayClass([$change]),
        ]));

        $json = $transaction->jsonSerialize();

        $this->assertSame(31, $json["transactionID"]);
        $this->assertSame($transaction->timestamp, $json["timestamp"]);
        $this->assertSame("serializer", $json["author"]);
        $this->assertSame("com.example.serializer", $json["bundleID"]);
        $this->assertSame("writer", $json["contextName"]);
        $this->assertSame("process-serializer", $json["processID"]);
        $this->assertSame("store-C", $json["storeID"]);
        $this->assertInstanceOf(ArrayClass::class, $json["changes"]);
        $this->assertEquals($change->jsonSerialize(), $json["changes"]->first);
    }

    public function testJsonSerializationKeepsAbsentChangesNull(): void
    {
        $transaction = new PersistentHistoryTransaction(new Dictionary(["transactionID" => 44]));

        $this->assertNull($transaction->jsonSerialize()["changes"]);
    }

    public function testDescriptionIdentifiesTheTransactionAndItsMetadata(): void
    {
        $transaction = new PersistentHistoryTransaction(new Dictionary([
            "transactionID" => 52,
            "timestamp" => "2025-06-07 08:09:10 UTC",
            "author" => "description-author",
            "bundleID" => "com.example.description",
            "contextName" => "description-context",
        ]));

        $this->assertStringContainsString("PersistentHistoryTransaction", $transaction->description);
        $this->assertStringContainsString("52", $transaction->description);
        $this->assertStringContainsString("description-author", $transaction->description);
        $this->assertStringContainsString("com.example.description", $transaction->description);
        $this->assertStringContainsString("description-context", $transaction->description);
    }
}
