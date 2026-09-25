<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ExpressionDescription;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\PersistentStore;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Predicates\Expression;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/**
 * @property string $title
 * @property float $price
 * @property Set<ExprPart> $parts
 */
final class ExprProduct extends ManagedObject
{
}

/**
 * @property string $code
 * @property ExprProduct|null $product
 */
abstract class ExprAbstractPart extends ManagedObject
{
}

/**
 * @property string $code
 * @property ExprProduct|null $product
 */
final class ExprPart extends ExprAbstractPart
{
}

/**
 * Covers two things an atomic store does that nothing else exercised.
 *
 * An ExpressionDescription in propertiesToFetch names a value that is not on the entity at all —
 * the store computes it per row and writes it into the serialized result, which only makes sense
 * for a dictionary fetch. ExpressionDescription itself sat at 0%: no suite built one.
 *
 * And the cardinality contract for an unresolved to-many: a REQUIRED one answers with an empty
 * ArrayClass, while everything else answers Nil. The distinction is what separates "this
 * relationship is empty" from "this relationship has no value", and only the required branch was
 * uncovered.
 *
 * Exercised through XMLObjectStore, since AtomicStore is abstract and MemoryObjectStore has no
 * load(). Each test gets its own temporary file.
 */
final class AtomicStoreFetchExpressionTest extends TestCase
{
    private URL $storeURL;

    /**
     * Product -1----*- Part, with the to-many REQUIRED so the store takes the empty-ArrayClass
     * branch rather than answering Nil.
     */
    private static function model(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $price = new AttributeDescription();
        $price->name = "price";
        $price->type = AttributeType::double;

        $parts = new RelationshipDescription();
        $parts->name = "parts";
        $parts->lazyDestinationEntityName = "ExprPart";
        $parts->lazyInverseRelationshipName = "product";
        $parts->isToMany = true;
        $parts->isOptional = false;

        $product = new EntityDescription();
        $product->name = "ExprProduct";
        $product->managedObjectClassName = ExprProduct::class;
        $product->properties = new ArrayClass([$title, $price, $parts]);

        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $productRelationship = new RelationshipDescription();
        $productRelationship->name = "product";
        $productRelationship->lazyDestinationEntityName = "ExprProduct";
        $productRelationship->lazyInverseRelationshipName = "parts";

        $part = new EntityDescription();
        $part->name = "ExprPart";
        $part->managedObjectClassName = ExprPart::class;
        $part->properties = new ArrayClass([$code, $productRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$product, $part]);
        return $model;
    }

    /**
     * The same two entities, but each relationship points at an ABSTRACT entity that the other
     * one specialises. The store has no nodes of an abstract entity, so its scan cannot answer
     * and the cardinality branch at the end of newValueForRelationship decides instead.
     */
    private static function abstractDestinationModel(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $price = new AttributeDescription();
        $price->name = "price";
        $price->type = AttributeType::double;

        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;

        $abstractPart = new EntityDescription();
        $abstractPart->name = "ExprAbstractPart";
        $abstractPart->managedObjectClassName = ExprAbstractPart::class;
        $abstractPart->isAbstract = true;

        $parts = new RelationshipDescription();
        $parts->name = "parts";
        $parts->lazyDestinationEntityName = "ExprAbstractPart";
        $parts->lazyInverseRelationshipName = "product";
        $parts->isToMany = true;
        $parts->isOptional = false;

        $product = new EntityDescription();
        $product->name = "ExprProduct";
        $product->managedObjectClassName = ExprProduct::class;
        $product->properties = new ArrayClass([$title, $price, $parts]);

        $productRelationship = new RelationshipDescription();
        $productRelationship->name = "product";
        $productRelationship->lazyDestinationEntityName = "ExprProduct";
        $productRelationship->lazyInverseRelationshipName = "parts";
        $abstractPart->properties = new ArrayClass([$code, $productRelationship]);

        $part = new EntityDescription();
        $part->name = "ExprPart";
        $part->managedObjectClassName = ExprPart::class;
        $part->superentity = $abstractPart;
        $part->properties = new ArrayClass([]);

        $abstractPart->subentities = new ArrayClass([$part]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$product, $abstractPart]);
        return $model;
    }

    /**
     * A fresh stack whose relationships point at an abstract entity.
     *
     * @throws Exception
     */
    private function abstractDestinationContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::abstractDestinationModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory
            ->appendingPathComponent(new UUID()->uuidString)
            ->appendingPathExtension("xml");
    }

    /** @throws Exception */
    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    /**
     * A fresh stack over this test's own store file.
     *
     * @throws Exception
     */
    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * An expression description is computed per row and lands in the dictionary result under its
     * own name, beside the attributes the request asked for.
     *
     * @throws Exception
     */
    public function testAnExpressionDescriptionIsComputedIntoTheDictionaryResult(): void
    {
        $context = $this->context();
        $product = new ExprProduct($context);
        $product->title = "widget";
        $product->price = 10.0;
        $context->save();

        $doubled = new ExpressionDescription();
        $doubled->name = "doubledPrice";
        $doubled->resultType = AttributeType::double;
        $doubled->expression = Expression::expressionForBlock(function (mixed $object): float {
            /** @var ArrayClass<Dictionary<mixed>> $object */
            return 2.0 * (float)$object->first["price"];
        });

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $request = ExprProduct::fetchRequest();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToFetch = new ArrayClass([$title, $doubled]);

        $rows = $context->fetch($request);

        $this->assertSame(1, $rows->count, "the fetch returns the one saved row");
        /** @var Dictionary<mixed> $row */
        $row = $rows->first;
        $this->assertSame("widget", (string)$row["title"], "the requested attribute is present");
        $this->assertSame(20.0, (float)$row["doubledPrice"], "the expression is computed into the result under its own name");
    }

    /**
     * An atomic store answers a relationship by scanning its cache nodes for the destination
     * entity, which it cannot do when that entity is ABSTRACT — there are no nodes of an
     * abstract entity, only of its subentities. The lookup is skipped entirely and the answer
     * falls through to the cardinality contract at the end of the method: a required to-many is
     * an empty list, because the relationship exists and is empty, which is a different answer
     * from having no value at all.
     *
     * The abstract destination is what reaches this branch. A concrete one is answered by the
     * node scan above it and never gets here, so a test built on an ordinary model passes
     * without running the code it names — measured, before this fixture was reshaped.
     *
     * @throws Exception
     */
    public function testARequiredToManyOntoAnAbstractEntityResolvesToAnEmptyList(): void
    {
        $context = $this->abstractDestinationContext();
        $product = new ExprProduct($context);
        $product->title = "widget";
        $product->price = 10.0;
        $context->save();

        $store = $context->persistentStoreCoordinator?->persistentStores->first;
        $this->assertInstanceOf(PersistentStore::class, $store, "the stack must have a store");
        /** @var RelationshipDescription $parts */
        $parts = EntityDescription::entity("ExprProduct", $context)->relationshipsByName["parts"];
        $this->assertFalse($parts->isOptional, "precondition: the relationship is required");
        $this->assertTrue($parts->destinationEntity->isAbstract, "precondition: the destination is abstract, so no node can be scanned for it");

        $value = $store->newValueForRelationship($parts, $product->objectID, $context);

        $this->assertInstanceOf(ArrayClass::class, $value, "a required to-many answers with a list rather than Nil");
        $this->assertTrue($value->isEmpty, "and the list is empty, because nothing points back at the product");
    }

    /**
     * The contrast that gives the previous test its meaning: on the same abstract footing, a
     * to-ONE answers Nil, since there is no collection for it to be the empty case of.
     *
     * @throws Exception
     */
    public function testAToOneOntoAnAbstractEntityResolvesToNil(): void
    {
        $context = $this->abstractDestinationContext();
        $part = new ExprPart($context);
        $part->code = "P1";
        $context->save();

        $store = $context->persistentStoreCoordinator?->persistentStores->first;
        $this->assertInstanceOf(PersistentStore::class, $store, "the stack must have a store");
        /** @var RelationshipDescription $product */
        $product = EntityDescription::entity("ExprPart", $context)->relationshipsByName["product"];

        $value = $store->newValueForRelationship($product, $part->objectID, $context);

        $this->assertInstanceOf(Nil::class, $value, "an unset to-one answers Nil, not an empty list");
    }
}
