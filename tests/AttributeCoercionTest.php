<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Number;
use Sabatier\Foundation\SensitiveValue;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * Covers attribute coercion — the boundary where a value of whatever PHP type a caller supplied
 * becomes the type the model declares.
 *
 * This is why `declare(strict_types=1)` is absent from much of src/: the type is decided at
 * runtime by the attribute, not at compile time, and everything crossing the store boundary
 * passes through here. It is also why getting it wrong is quiet — a string where an int belongs
 * is cast rather than refused, and the damage surfaces as a wrong value much later.
 *
 * Two flags run through every case and are asserted as such. `$isOptional` decides whether null
 * survives or falls back to the type's own empty value, and `$write` decides whether a date, a
 * UUID or a URL comes back as its object or as the string the store keeps.
 */
final class AttributeCoercionTest extends TestCase
{
    /** An attribute of the given type, optional or not. */
    private static function attribute(AttributeType $type, bool $isOptional = true, mixed $defaultValue = null): AttributeDescription
    {
        $attribute = new AttributeDescription();
        $attribute->name = "value";
        $attribute->type = $type;
        $attribute->isOptional = $isOptional;
        if ($defaultValue !== null) {
            $attribute->defaultValue = $defaultValue;
        }
        return $attribute;
    }

    /** Coerces through the property-driven entry point, which is what the framework calls. */
    private static function coerced(mixed $value, AttributeDescription $attribute, bool $write = false): mixed
    {
        ManagedObject::coerceValue($value, $attribute, $write);
        return $value;
    }

    // --- Scalars ---

    /**
     * Every numeric spelling of a value reaches the same int. The store hands back strings for
     * integer columns, so a column read never matches a value written from PHP unless both
     * converge here.
     *
     * @return array<string, array{mixed}>
     */
    public static function integerSpellings(): array
    {
        return [
            "int" => [42],
            "numeric string" => ["42"],
            "float" => [42.0],
            "Number" => [new Number(42)],
        ];
    }

    #[DataProvider("integerSpellings")]
    public function testEverySpellingOfAnIntegerCoercesToTheSameInt(mixed $value): void
    {
        $this->assertSame(42, self::coerced($value, self::attribute(AttributeType::integer32)));
    }

    public function testAFloatingAttributeKeepsItsFraction(): void
    {
        $this->assertSame(1.5, self::coerced("1.5", self::attribute(AttributeType::double)));
    }

    /**
     * A boolean reads back as a bool rather than as the 0/1 the store keeps, so a caller can
     * test it directly instead of comparing against an integer.
     */
    public function testABooleanAttributeReadsBackAsABool(): void
    {
        $this->assertTrue(self::coerced(1, self::attribute(AttributeType::boolean)));
        $this->assertFalse(self::coerced(0, self::attribute(AttributeType::boolean)));
    }

    /**
     * Writing a boolean yields the int the column holds — the store has no boolean type, and a
     * PHP false written verbatim would land as an empty string.
     */
    public function testWritingABooleanYieldsTheIntegerTheColumnHolds(): void
    {
        $this->assertSame(1, self::coerced(true, self::attribute(AttributeType::boolean), write: true));
        $this->assertSame(0, self::coerced(false, self::attribute(AttributeType::boolean), write: true));
    }

    public function testANumberIsUnwrappedToItsScalar(): void
    {
        $this->assertSame("7", self::coerced(new Number(7), self::attribute(AttributeType::string)));
    }

    // --- Null and the optional flag ---

    /**
     * An optional attribute keeps null. This is the whole point of the flag: absent has to stay
     * distinguishable from zero, or a report of "no value recorded" becomes "recorded as 0".
     *
     * @return array<string, array{AttributeType}>
     */
    public static function scalarTypes(): array
    {
        return [
            "integer32" => [AttributeType::integer32],
            "double" => [AttributeType::double],
            "string" => [AttributeType::string],
            "boolean" => [AttributeType::boolean],
        ];
    }

    #[DataProvider("scalarTypes")]
    public function testAnOptionalAttributeKeepsNull(AttributeType $type): void
    {
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->assertNull(self::coerced(null, self::attribute($type, isOptional: true)));
    }

    /**
     * The same rule one level down, where it is actually decided.
     *
     * coerceValue short-circuits on null before reaching coercedValue, so the property-driven
     * tests above never exercise the optional branch inside it — removing that branch leaves
     * them all passing. This asserts it directly, since coercedValue is the entry point a
     * caller with a raw type rather than a property uses.
     *
     * @throws Exception
     */
    #[DataProvider("scalarTypes")]
    public function testCoercingNullDirectlyRespectsTheOptionalFlag(AttributeType $type): void
    {
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->assertNull(ManagedObject::coercedValue(null, $type, isOptional: true), "optional keeps null");
        $this->assertNotNull(ManagedObject::coercedValue(null, $type, isOptional: false), "required falls back to the type's empty value");
    }

    /**
     * A required attribute has no way to hold null, so it falls back to the type's own empty
     * value. That fallback is deliberate and it is why an object must always be given something
     * to save: a record whose attributes are all defaults carries no information but saves
     * without complaint.
     */
    public function testARequiredAttributeFallsBackToItsTypesEmptyValue(): void
    {
        $this->assertSame(0, self::coerced(null, self::attribute(AttributeType::integer32, isOptional: false)));
        $this->assertSame("", self::coerced(null, self::attribute(AttributeType::string, isOptional: false)));
        $this->assertFalse(self::coerced(null, self::attribute(AttributeType::boolean, isOptional: false)));
    }

    /**
     * A required attribute with a declared default falls back to THAT rather than to the type's
     * empty value.
     */
    public function testADeclaredDefaultWinsOverTheTypesEmptyValue(): void
    {
        $this->assertSame(9, self::coerced(null, self::attribute(AttributeType::integer32, isOptional: false, defaultValue: 9)));
    }

    /**
     * An empty string on an optional scalar becomes null, not zero. A form submits "" for a
     * field the user left alone, and coercing that to 0 would record a measurement nobody took.
     */
    public function testAnEmptyStringOnAnOptionalScalarBecomesNull(): void
    {
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->assertNull(self::coerced("", self::attribute(AttributeType::integer32, isOptional: true)));
        /** @noinspection PhpRedundantOptionalArgumentInspection */
        $this->assertNull(self::coerced("", self::attribute(AttributeType::double, isOptional: true)));
    }

    /**
     * On a REQUIRED scalar the same empty string falls through to the numeric coercion and lands
     * at zero, because null is not available to it.
     */
    public function testAnEmptyStringOnARequiredScalarBecomesZero(): void
    {
        $this->assertSame(0, self::coerced("", self::attribute(AttributeType::integer32, isOptional: false)));
    }

    // --- Dates, UUIDs and URLs ---

    /**
     * Reading a date yields a Date; writing one yields the string the column keeps. Both
     * directions matter: the store cannot persist an object, and a caller cannot compare a
     * string against Date::now().
     */
    public function testADateReadsAsAnObjectAndWritesAsAString(): void
    {
        $attribute = self::attribute(AttributeType::date);

        $this->assertInstanceOf(Date::class, self::coerced("2026-09-15 08:00:00", $attribute));
        $this->assertSame("2026-09-15 08:00:00", self::coerced("2026-09-15 08:00:00", $attribute, write: true));
    }

    public function testADateAlreadyAnObjectIsLeftAlone(): void
    {
        $date = Date::now();

        $this->assertSame($date, self::coerced($date, self::attribute(AttributeType::date)));
    }

    /**
     * A required date with no value becomes now, since there is no empty date to fall back to.
     */
    public function testARequiredDateWithNoValueBecomesNow(): void
    {
        $this->assertInstanceOf(Date::class, self::coerced(null, self::attribute(AttributeType::date, isOptional: false)));
    }

    public function testAUUIDReadsAsAnObjectAndWritesAsAString(): void
    {
        $uuid = new UUID();
        $attribute = self::attribute(AttributeType::uuid);

        $this->assertInstanceOf(UUID::class, self::coerced($uuid->uuidString, $attribute));
        $this->assertSame($uuid->uuidString, self::coerced($uuid->uuidString, $attribute, write: true));
    }

    /**
     * A required UUID with no value is generated rather than refused — an identifier that must
     * exist is minted here.
     */
    public function testARequiredUUIDWithNoValueIsGenerated(): void
    {
        $this->assertInstanceOf(UUID::class, self::coerced(null, self::attribute(AttributeType::uuid, isOptional: false)));
    }

    public function testAURIReadsAsAnObjectAndWritesAsAString(): void
    {
        $attribute = self::attribute(AttributeType::uri);

        $this->assertInstanceOf(URL::class, self::coerced("https://example.test/a", $attribute));
        $this->assertSame("https://example.test/a", self::coerced("https://example.test/a", $attribute, write: true));
    }

    // --- Composite attributes and relationships ---

    /**
     * A composite attribute is a Dictionary; anything else becomes an empty one rather than
     * being stored as a scalar the store could not read back.
     */
    public function testACompositeAttributeIsAlwaysADictionary(): void
    {
        $attribute = self::attribute(AttributeType::compositeAttributeType);

        $this->assertInstanceOf(Dictionary::class, self::coerced(new Dictionary(["a" => 1]), $attribute));
        $this->assertInstanceOf(Dictionary::class, self::coerced("not a dictionary", $attribute));
    }

    /**
     * A to-many relationship is always a Set, even when handed an ArrayClass or nothing at all —
     * the collection has to exist for a mutator to have something to add to.
     *
     * @throws Exception
     */
    public function testAToManyRelationshipIsAlwaysASet(): void
    {
        $relationship = new RelationshipDescription();
        $relationship->name = "items";
        $relationship->lazyDestinationEntityName = "CoercionRow";
        $relationship->isToMany = true;

        $value = new ArrayClass();
        ManagedObject::coerceValue($value, $relationship);
        $this->assertInstanceOf(Set::class, $value, "an ArrayClass is converted");

        $absent = null;
        ManagedObject::coerceValue($absent, $relationship);
        $this->assertInstanceOf(Set::class, $absent, "and an absent value becomes an empty set");
    }

    // --- Refusals ---

    /**
     * A value that cannot be the declared type is refused on a REQUIRED attribute rather than
     * silently cast. The refusal is what stops a wrong type from reaching the column; an
     * optional attribute tolerates it, since it can still record nothing.
     */
    public function testAnUncoercibleValueIsRefusedOnARequiredAttribute(): void
    {
        $value = new Dictionary(["not" => "a date"]);

        $this->expectException(InternalInconsistencyException::class);
        ManagedObject::coerceValue($value, self::attribute(AttributeType::date, isOptional: false));
    }

    /**
     * The undefined type is not a type at all — it is the unset default of a model that was
     * never finished, and coercing against it is refused outright.
     */
    public function testTheUndefinedAttributeTypeIsRefused(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        ManagedObject::coercedValue("anything", AttributeType::undefined);
    }

    // --- The other direction: sanitizing a snapshot for the store ---

    /**
     * An entity carrying one attribute of the given type, plus the keys every snapshot has.
     *
     * @throws Exception
     * @noinspection PhpSameParameterValueInspection
     */
    private static function entityWithAttribute(AttributeType $type): EntityDescription
    {
        $value = new AttributeDescription();
        $value->name = "value";
        $value->type = $type;

        $entity = new EntityDescription();
        $entity->name = "SanitizedRow";
        $entity->properties = new ArrayClass([$value]);

        /** @noinspection PhpObjectFieldsAreOnlyWrittenInspection */
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $entity;
    }

    /**
     * Sanitizing reduces each object to the scalar the column holds. This is the write side of
     * the same boundary the coercion tests above cover: the store cannot persist a Date, a UUID
     * or a URL, only the string each one reduces to.
     *
     * @throws Exception
     */
    public function testSanitizingReducesObjectsToTheirStoredScalars(): void
    {
        $entity = self::entityWithAttribute(AttributeType::string);
        $date = Date::now();
        $uuid = new UUID();

        $sanitized = $entity->sanitizeSnapshot(new Dictionary(["value" => $date]));
        $this->assertSame($date->description, $sanitized["value"], "a date reduces to its description");

        $sanitized = $entity->sanitizeSnapshot(new Dictionary(["value" => $uuid]));
        $this->assertSame($uuid->uuidString, $sanitized["value"], "a UUID to its string");

        $sanitized = $entity->sanitizeSnapshot(new Dictionary(["value" => new URL("https://example.test/a")]));
        $this->assertSame("https://example.test/a", $sanitized["value"], "a URL to its absolute string");

        $sanitized = $entity->sanitizeSnapshot(new Dictionary(["value" => new Number(5)]));
        $this->assertSame(5, $sanitized["value"], "and a Number to its scalar");
    }

    /**
     * A sensitive value is dropped rather than written. That is the point of the type: it marks
     * a value the model holds in memory but must never reach a column, and sanitizing is the
     * single place that decision is enforced.
     *
     * @throws Exception
     */
    public function testASensitiveValueIsNotWrittenToTheStore(): void
    {
        $entity = self::entityWithAttribute(AttributeType::string);

        $sanitized = $entity->sanitizeSnapshot(new Dictionary(["value" => new SensitiveValue("secret")]));

        $this->assertNull($sanitized["value"], "a sensitive value is blanked on its way to the store");
    }

    /**
     * Only modelled keys survive. A snapshot arrives with whatever the caller put in it, and a
     * key the entity does not declare has no column to go to.
     *
     * @throws Exception
     */
    public function testAnUnmodelledKeyIsDroppedFromTheSnapshot(): void
    {
        $entity = self::entityWithAttribute(AttributeType::string);

        $sanitized = $entity->sanitizeSnapshot(new Dictionary(["value" => "kept", "stray" => "dropped"]));

        $this->assertSame("kept", $sanitized["value"]);
        $this->assertNull($sanitized["stray"], "a key the entity does not declare does not survive");
    }

    // --- Through a real model ---

    /**
     * The same coercion reached the way an application reaches it: assigning a property. What is
     * asserted is that the value read back is the coerced one, not what was assigned.
     *
     * @throws Exception
     */
    public function testAssigningAPropertyCoercesThroughTheModel(): void
    {
        $count = new AttributeDescription();
        $count->name = "count";
        $count->type = AttributeType::integer32;

        $entity = new EntityDescription();
        $entity->name = "CoercionRow";
        $entity->properties = new ArrayClass([$count]);

        /** @noinspection PhpObjectFieldsAreOnlyWrittenInspection */
        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);

        $value = "17";
        ManagedObject::coerceValue($value, $count);

        $this->assertSame(17, $value, "a string assigned to an integer attribute is stored as an int");
    }
}
