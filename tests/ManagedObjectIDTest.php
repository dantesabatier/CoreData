<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\ComparisonResult;

/**
 * Tests for src/ManagedObjectID.php.
 *
 * Regression guards:
 *  - isEqual compares storeIdentifier/entityName/referenceObject directly instead of
 *    building two uriRepresentation() URLs, and keeps its int/string stringification;
 *  - isEqual compares those fields exactly. Every one of them is generated, never typed
 *    by hand, and a case-insensitive collation made each comparison some 57 times
 *    slower on a path every Set of object IDs walks;
 *  - an object ID with no persistent store is always temporary;
 *  - __serialize/__unserialize round-trips the identity triplet without needing the
 *    entity to be resolvable.
 */
final class ManagedObjectIDTest extends TestCase
{
    /**
     * Builds a one-entity model so the entity is finalized (non-editable) the same way
     * the coordinator sees it.
     */
    private static function makeEntity(string $name): EntityDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = "label";
        $attribute->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = $name;
        $entity->properties = new ArrayClass([$attribute]);

        /** @noinspection PhpObjectFieldsAreOnlyWrittenInspection */
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $entity;
    }

    public function testCompareOrdersByEntityThenReference(): void
    {
        $person = self::makeEntity("Person");
        $place = self::makeEntity("Place");

        $this->assertSame(ComparisonResult::orderedAscending, new ManagedObjectID($person, 2)->compare(new ManagedObjectID($person, 10)), "references compare as numbers");
        $this->assertSame(ComparisonResult::orderedSame, new ManagedObjectID($person, 7)->compare(new ManagedObjectID($person, 7)));
        $this->assertSame(ComparisonResult::orderedAscending, new ManagedObjectID($person, 99)->compare(new ManagedObjectID($place, 1)), "the entity name decides before the reference");
    }

    public function testConstructionKeepsEntityAndReference(): void
    {
        $entity = self::makeEntity("Person");
        $objectID = new ManagedObjectID($entity, "ABC-123");

        $this->assertSame($entity, $objectID->entity, "the entity passed to the constructor is kept");
        $this->assertSame("ABC-123", $objectID->referenceObject, "the reference object passed to the constructor is kept");
        $this->assertNull($objectID->persistentStore, "a standalone object ID has no persistent store");
        $this->assertTrue($objectID->isTemporaryID, "an object ID with no persistent store is temporary");
    }

    public function testIntReferenceIsKeptAsInt(): void
    {
        $numericID = new ManagedObjectID(self::makeEntity("Person"), 42);

        $this->assertSame(42, $numericID->referenceObject);
        $this->assertTrue($numericID->isTemporaryID, "even a numeric reference is temporary while there is no persistent store");
    }

    public function testUriRepresentationCarriesTheIdentityComponents(): void
    {
        $objectID = new ManagedObjectID(self::makeEntity("Person"), "ABC-123");

        // With no persistent store the storeIdentifier is null, so the URI degrades to a
        // bare "/{entity}/{reference}" path; the identity components must still be there.
        $uri = $objectID->uriRepresentation();
        $this->assertStringContainsString("Person", $uri->absoluteString);
        $this->assertStringContainsString("ABC-123", $uri->absoluteString);
        $this->assertSame("ABC-123", $uri->lastPathComponent, "the reference object is the last path component");
    }

    public function testIsEqualMatchesSameEntityAndReference(): void
    {
        $entity = self::makeEntity("Person");
        $objectID = new ManagedObjectID($entity, "ABC-123");
        $same = new ManagedObjectID($entity, "ABC-123");

        $this->assertTrue($objectID->isEqual($same), "two IDs with the same entity and reference are equal");
        $this->assertTrue($same->isEqual($objectID), "equality is symmetric");
        $this->assertTrue($objectID->isEqual($objectID), "an ID is equal to itself");
    }

    public function testIsEqualComparesExactly(): void
    {
        $entity = self::makeEntity("Person");
        $objectID = new ManagedObjectID($entity, "ABC-123");

        $this->assertFalse($objectID->isEqual(new ManagedObjectID($entity, "abc-123")), "a reference differing only in case is another reference");
        $this->assertFalse($objectID->isEqual(new ManagedObjectID(self::makeEntity("person"), "ABC-123")), "and so is an entity name");
    }

    public function testIsEqualStringifiesIntReferencesLikeTheUriCompareItReplaced(): void
    {
        $entity = self::makeEntity("Person");

        $this->assertTrue(new ManagedObjectID($entity, 42)->isEqual(new ManagedObjectID($entity, "42")));
    }

    public function testIsEqualRejectsDifferentIdentities(): void
    {
        $entity = self::makeEntity("Person");
        $objectID = new ManagedObjectID($entity, "ABC-123");

        $this->assertFalse($objectID->isEqual(new ManagedObjectID($entity, "XYZ-999")), "IDs with different reference objects are not equal");
        $this->assertFalse($objectID->isEqual(new ManagedObjectID(self::makeEntity("Company"), "ABC-123")), "IDs with different entities are not equal");
        $this->assertFalse($objectID->isEqual("ABC-123"), "an object ID is never equal to a non-ManagedObjectID value");
        $this->assertFalse($objectID->isEqual(null), "an object ID is never equal to null");
    }

    public function testSerializationRoundTripsTheIdentity(): void
    {
        $objectID = new ManagedObjectID(self::makeEntity("Person"), "ABC-123");
        $unserialized = unserialize(serialize($objectID));

        $this->assertInstanceOf(ManagedObjectID::class, $unserialized);
        $this->assertSame("ABC-123", $unserialized->referenceObject, "the reference object survives the round trip");
        $this->assertTrue($objectID->isEqual($unserialized), "the unserialized ID is equal to the original");
    }

    public function testUnserializationKeepsTheArchivedStoreIdentifierResolved(): void
    {
        $objectID = new ManagedObjectID(self::makeEntity("Person"), "unused");
        $objectID->__unserialize([
            "entityName" => "Person",
            "referenceObject" => 42,
            "storeIdentifier" => "archived-store",
        ]);

        $this->assertSame("archived-store", $objectID->storeIdentifier, "reading the identifier must not replace the archived value with null when no store is attached yet");
    }

    public function testJsonSerializeIsTheReferenceObject(): void
    {
        $entity = self::makeEntity("Person");

        $this->assertSame("ABC-123", new ManagedObjectID($entity, "ABC-123")->jsonSerialize());
        $this->assertSame(42, new ManagedObjectID($entity, 42)->jsonSerialize(), "jsonSerialize keeps the int type");
    }
}
