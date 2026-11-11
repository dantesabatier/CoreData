<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\NullRowCache;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\RowCache;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
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

final class SensitiveDictionaryTest extends SQLMigrationTestCase
{
    /** @var class-string<RowCache> */
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
    private function dictionary(ManagedObjectContext $context, string $entityName, ArrayClass $propertiesToFetch, ?Dictionary $serialization = null): Dictionary
    {
        /** @var Dictionary<mixed> $row */
        $row = $this->first($context, function () use ($entityName, $propertiesToFetch, $serialization): FetchRequest {
            $request = new FetchRequest($entityName);
            $request->resultType = FetchRequestResultType::dictionaryResultType;
            $request->propertiesToFetch = $propertiesToFetch;
            if ($serialization) {
                $request->serialization = $serialization;
            }
            return $request;
        });
        return $row;
    }

    /**
     * @param callable(): FetchRequest $request
     * @throws Exception
     */
    private function first(ManagedObjectContext $context, callable $request): mixed
    {
        $result = null;
        $context->performBlockAndWait(function () use ($context, $request, &$result): void {
            $result = $context->fetch($request())->first;
        });
        return $result;
    }

    /** @throws Exception */
    public function testADictionaryLeavesOutASensitiveAttributeAskedForByName(): void
    {
        $row = $this->dictionary($this->seed(), "SDAccount", new ArrayClass(["login", "secret"]));

        $this->assertSame("ana", $row["login"]);
        $this->assertFalse($row->offsetExists("secret"));
    }

    /** @throws Exception */
    public function testADictionaryLeavesOutASensitiveAttributeOfARelationship(): void
    {
        $row = $this->dictionary($this->seed(), "SDNote", new ArrayClass(["text", "account"]));
        /** @var Dictionary<mixed> $account */
        $account = $row["account"];

        $this->assertSame("ana", $account["login"]);
        $this->assertFalse($account->offsetExists("secret"));
    }

    /** @throws Exception */
    public function testAGivenSerializationIsKeptAsIs(): void
    {
        $row = $this->dictionary($this->seed(), "SDAccount", new ArrayClass(["secret"]), Dictionary::dictionaryWithArray(["secret" => AttributeType::string]));

        $this->assertSame("ana-hash", $row["secret"]);
    }

    /** @throws Exception */
    public function testAnObjectStillLoadsItsSensitiveAttribute(): void
    {
        $context = $this->seed();
        $secret = null;
        $context->performBlockAndWait(function () use ($context, &$secret): void {
            /** @var SDAccount $account */
            $account = $context->fetch(new FetchRequest("SDAccount"))->first;
            $secret = $account->secret;
        });

        $this->assertSame("ana-hash", $secret);
    }

    /** @throws Exception */
    public function testARelatedObjectStillLoadsItsSensitiveAttribute(): void
    {
        $context = $this->seed();
        $secret = null;
        $context->performBlockAndWait(function () use ($context, &$secret): void {
            /** @var SDNote $note */
            $note = $context->fetch(new FetchRequest("SDNote"))->first;
            $secret = $note->account?->secret;
        });

        $this->assertSame("ana-hash", $secret);
    }
}
