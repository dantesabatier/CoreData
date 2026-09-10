<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\SensitiveValue;
use Sabatier\Foundation\URL;

/**
 * Tests for the SensitiveValue guard in ManagedObject::validateValueForKey().
 *
 * Regression guard: an integer attribute marked isSensitive is masked as a SensitiveValue on the way
 * out, and the mask travelled back into validateValueForKey(). SensitiveValue is final and keeps its
 * payload in a private readonly property, so there is nothing to coerce: coercion emitted
 * "could not be converted to int" and would have persisted "int(SensitiveValue)" from __toString().
 * The set is rejected before coercion runs.
 */
final class SensitiveValueCoercionTest extends TestCase
{
    private string $storePath;
    private ManagedObjectContext $context;

    private static function model(): ManagedObjectModel
    {
        $pin = new AttributeDescription();
        $pin->name = "pin";
        $pin->type = AttributeType::integer64;
        $pin->isSensitive = true;

        $token = new AttributeDescription();
        $token->name = "token";
        $token->type = AttributeType::string;
        $token->isSensitive = true;

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::string;

        $tier = new AttributeDescription();
        $tier->name = "tier";
        $tier->type = AttributeType::integer32;
        $tier->isSensitive = true;

        $entity = new EntityDescription();
        $entity->name = "Account";
        $entity->managedObjectClassName = SensitiveAccount::class;
        $entity->properties = new ArrayClass([$pin, $token, $label, $tier]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-sensitivevalue-test-" . uniqid("", true) . ".xml";

        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, new URL("file:///" . str_replace("\\", "/", $this->storePath)));
        $this->context = new ManagedObjectContext();
        $this->context->persistentStoreCoordinator = $coordinator;
    }

    #[Override]
    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sensitiveKeyProvider(): array
    {
        return ["integer attribute" => ["pin"], "string attribute" => ["token"]];
    }

    /**
     * The reported crash: the mask reached the coercion helpers, which could not convert it to int.
     */
    #[DataProvider("sensitiveKeyProvider")]
    public function testMaskedValueIsRejected(string $key): void
    {
        $account = new SensitiveAccount($this->context);
        $value = new SensitiveValue(1234);

        $this->assertFalse($account->validateValueForKey($value, $key), "the mask is rejected before coercion");
    }

    /**
     * The mask is an outbound value, so rejecting the set must leave it alone rather than rewriting it
     * to null or to its own description.
     */
    public function testRejectedMaskIsNotRewritten(): void
    {
        $account = new SensitiveAccount($this->context);
        $mask = new SensitiveValue(1234);
        $value = $mask;

        $account->validateValueForKey($value, "pin");

        $this->assertSame($mask, $value, "the mask is passed over, not coerced or discarded");
    }

    /**
     * The whole point of the guard: setting the mask cannot overwrite the real value with
     * "int(SensitiveValue)", which is what __toString() yields and what coercion used to store.
     */
    public function testSettingTheMaskLeavesTheStoredValueIntact(): void
    {
        $account = new SensitiveAccount($this->context);
        $account->setValueForKey(1234, "pin");

        $account->setValueForKey(new SensitiveValue(9999), "pin");

        $this->assertSame(1234, $account->valueForKey("pin"), "the masked set is a no-op, the real value survives");
    }

    /**
     * The guard keys off the value, not the attribute, so a real value for a sensitive attribute is
     * still validated and coerced.
     */
    public function testPlainValueForASensitiveAttributeStillCoerces(): void
    {
        $account = new SensitiveAccount($this->context);
        $value = "1234";

        $this->assertTrue($account->validateValueForKey($value, "pin"), "a real value is unaffected by the guard");
        $this->assertSame(1234, $value, "a real value is still coerced to the attribute type");
    }

    /**
     * A non-sensitive attribute never produces a mask, but a caller could still hand one over; it is
     * rejected the same way instead of reaching the type check.
     */
    public function testMaskedValueIsRejectedForANonSensitiveAttribute(): void
    {
        $account = new SensitiveAccount($this->context);
        $value = new SensitiveValue("plain");

        $this->assertFalse($account->validateValueForKey($value, "label"));
    }

    /**
     * Reaching the coercion helper with a mask means the guard was bypassed, so it raises instead of
     * inventing a value. This is the outside-caller path.
     */
    public function testCoercingAMaskDirectlyRaises(): void
    {
        $this->expectException(InternalInconsistencyException::class);

        ManagedObject::coercedValue(new SensitiveValue(1234), AttributeType::integer64);
    }

    /**
     * Models narrow the validate<Key>() signature to coordinate int to enum, so the guard has to run
     * ahead of the hook: handing it the mask would be a TypeError rather than a rejected set.
     */
    public function testMaskedValueIsRejectedBeforeATypedValidationHook(): void
    {
        $account = new SensitiveAccount($this->context);
        // hydrateAttributes() already ran the hook once during construction, so the count starts at 1.
        $baseline = $account->tierValidationCount;
        $value = new SensitiveValue(2);

        $this->assertFalse($account->validateValueForKey($value, "tier"), "the mask never reaches the typed hook");
        $this->assertSame($baseline, $account->tierValidationCount, "the hook is not invoked for a masked value");
    }

    /**
     * The hook still runs, and still coerces, for a real value on the same attribute.
     */
    public function testTypedValidationHookStillCoercesARealValue(): void
    {
        $account = new SensitiveAccount($this->context);
        $baseline = $account->tierValidationCount;
        $value = 2;

        $this->assertTrue($account->validateValueForKey($value, "tier"));
        $this->assertSame($baseline + 1, $account->tierValidationCount, "the hook is invoked for a real value");
        $this->assertSame(20, $value, "the hook's own coordination is preserved");
    }
}

/**
 * The model needs a real ManagedObject subclass registered via managedObjectClassName.
 */
/**
 * @property string $label
 * @property int $pin
 * @property int $tier
 * @property string $token
 */
final class SensitiveAccount extends ManagedObject
{
    public ?int $pin = null;
    public ?string $token = null;
    public ?string $label = null;
    public ?int $tier = null;
    public int $tierValidationCount = 0;

    /**
     * Mirrors how models narrow the hook to coordinate an int into a domain value.
     */
    public function validateTier(?int &$tier): bool
    {
        $this->tierValidationCount++;
        if (is_int($tier)) {
            $tier *= 10;
        }
        return true;
    }
}
