<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
use PDO;
use RuntimeException;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequestResultType;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectID;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\ValueTransformer;

final class StrictNoteTransformer extends ValueTransformer
{
    public const string Marker = "marked:";

    public static int $transformCount = 0;
    public static int $reverseCount = 0;

    public static function encoded(string $value): string
    {
        return self::Marker . strrev($value);
    }

    #[Override]
    public static function allowsReverseTransformation(): bool
    {
        return true;
    }

    #[Override]
    public function transformedValue(mixed $value): ?string
    {
        self::$transformCount++;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || str_starts_with($value, self::Marker)) {
            throw new RuntimeException("Encoding a value that is not decoded: " . var_export($value, true));
        }
        return self::encoded($value);
    }

    #[Override]
    public function reverseTransformedValue(mixed $value): ?string
    {
        self::$reverseCount++;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !str_starts_with($value, self::Marker)) {
            throw new RuntimeException("Decoding a value that is not encoded: " . var_export($value, true));
        }
        return strrev(substr($value, strlen(self::Marker)));
    }
}

/**
 * @property string|null $note
 * @property string $label
 * @property Dictionary<mixed>|null $extras
 * @property string|null $title
 */
final class Memo extends ManagedObject
{
}

final class SQLTransformableAttributeTest extends SQLMigrationTestCase
{
    private const string Note = "secret";
    private const string Fallback = "fallback";

    private static function model(): ManagedObjectModel
    {
        $note = new AttributeDescription();
        $note->name = "note";
        $note->type = AttributeType::transformable;
        $note->valueTransformerName = StrictNoteTransformer::class;

        $label = new AttributeDescription();
        $label->name = "label";
        $label->type = AttributeType::transformable;
        $label->valueTransformerName = StrictNoteTransformer::class;
        $label->isOptional = false;
        $label->defaultValue = self::Fallback;

        $extras = new AttributeDescription();
        $extras->name = "extras";
        $extras->type = AttributeType::transformable;
        $extras->attributeValueClassName = Dictionary::class;

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $memo = new EntityDescription();
        $memo->name = "Memo";
        $memo->managedObjectClassName = Memo::class;
        $memo->properties = new ArrayClass([$note, $label, $extras, $title]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$memo]);
        return $model;
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        StrictNoteTransformer::$transformCount = 0;
        StrictNoteTransformer::$reverseCount = 0;
    }

    /** @throws Exception */
    private function seed(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::model());
        $memo = new Memo($context);
        $memo->note = self::Note;
        $memo->extras = new Dictionary(["priority" => 2]);
        $memo->title = "draft";
        $context->save();
        return $context;
    }

    /** @return array<string, mixed> */
    private function storedRow(): array
    {
        return $this->pdo->query("SELECT note, label, title FROM " . self::DATABASE_NAME . ".Memo")->fetch(PDO::FETCH_ASSOC);
    }

    /** @throws Exception */
    private function fetchedMemo(): Memo
    {
        /** @var Memo */
        return $this->freshContext(self::model())->fetch(Memo::fetchRequest())->first;
    }

    /** @throws Exception */
    public function testAssigningAValueDoesNotRunTheTransformer(): void
    {
        $memo = new Memo($this->bootstrap(self::model()));
        StrictNoteTransformer::$reverseCount = 0;
        $memo->note = self::Note;
        $this->assertSame(self::Note, $memo->note);
        $this->assertSame(0, StrictNoteTransformer::$reverseCount);
    }

    /** @throws Exception */
    public function testSavingStoresTheValueEncodedOnce(): void
    {
        $this->seed();
        $this->assertSame(StrictNoteTransformer::encoded(self::Note), $this->storedRow()["note"]);
    }

    /** @throws Exception */
    public function testAnObjectReadsTheValueDecoded(): void
    {
        $this->seed();
        $this->assertSame(self::Note, $this->fetchedMemo()->note);
    }

    /** @throws Exception */
    public function testAFaultFulfilledFromTheStoreReadsTheValueDecoded(): void
    {
        $this->seed();
        $context = $this->freshContext(self::model());
        $request = Memo::fetchRequest();
        $request->resultType = FetchRequestResultType::managedObjectIDResultType;
        $request->includesPropertyValues = false;
        /** @var ManagedObjectID $objectID */
        $objectID = $context->fetch($request)->first;
        /** @var Memo $memo */
        $memo = $context->object($objectID);
        $this->assertTrue($memo->isFault);
        $this->assertSame(self::Note, $memo->note);
    }

    /** @throws Exception */
    public function testABatchedFetchReadsTheValueDecoded(): void
    {
        $context = $this->seed();
        $other = new Memo($context);
        $other->note = "other";
        $context->save();
        $request = Memo::fetchRequest();
        $request->fetchBatchSize = 1;
        $notes = $this->freshContext(self::model())->fetch($request)->map(fn(Memo $memo): ?string => $memo->note);
        $this->assertSame([self::Note, "other"], $notes->array);
    }

    /** @throws Exception */
    public function testADictionaryReadsTheValueDecoded(): void
    {
        $this->seed();
        $request = Memo::fetchRequest();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToFetch = new ArrayClass(["note"]);
        /** @var Dictionary<mixed>|null $row */
        $row = $this->freshContext(self::model())->fetch($request)->first;
        $this->assertSame(self::Note, $row?->valueForKey("note"));
    }

    /** @throws Exception */
    public function testSavingAReadObjectKeepsTheStoredValue(): void
    {
        $this->seed();
        $memo = $this->fetchedMemo();
        $memo->title = "final";
        $memo->managedObjectContext->save();
        $this->assertSame(["note" => StrictNoteTransformer::encoded(self::Note), "label" => StrictNoteTransformer::encoded(self::Fallback), "title" => "final"], $this->storedRow());
    }

    /** @throws Exception */
    public function testRefreshKeepsTheValueDecoded(): void
    {
        $this->seed();
        $memo = $this->fetchedMemo();
        $memo->managedObjectContext->refresh($memo);
        $this->assertSame(self::Note, $memo->note);
    }

    /** @throws Exception */
    public function testRollbackRestoresTheDecodedValue(): void
    {
        $this->seed();
        $memo = $this->fetchedMemo();
        $memo->note = "changed";
        $memo->managedObjectContext->processPendingChanges();
        $memo->managedObjectContext->rollback();
        $this->assertSame(self::Note, $memo->note);
    }

    /** @throws Exception */
    public function testANonOptionalAttributeTakesItsDefaultValueDecoded(): void
    {
        $this->seed();
        $this->assertSame(self::Fallback, $this->fetchedMemo()->label);
        $this->assertSame(StrictNoteTransformer::encoded(self::Fallback), $this->storedRow()["label"]);
    }

    /** @throws Exception */
    public function testTheDefaultTransformerRoundTripsAnObjectAndADictionary(): void
    {
        $this->seed();
        $this->assertTrue(new Dictionary(["priority" => 2])->isEqual($this->fetchedMemo()->extras));
        $request = Memo::fetchRequest();
        $request->resultType = FetchRequestResultType::dictionaryResultType;
        $request->propertiesToFetch = new ArrayClass(["extras"]);
        /** @var Dictionary<mixed>|null $row */
        $row = $this->freshContext(self::model())->fetch($request)->first;
        $this->assertTrue(new Dictionary(["priority" => 2])->isEqual($row?->valueForKey("extras")));
    }

    /**
     * @return ArrayClass<string|null>
     * @throws Exception
     */
    private function titlesMatching(Predicate $predicate): ArrayClass
    {
        $context = $this->seed();
        $empty = new Memo($context);
        $empty->title = "empty";
        $context->save();
        $request = Memo::fetchRequest();
        $request->predicate = $predicate;
        return $this->freshContext(self::model())->fetch($request)->map(fn(Memo $memo): ?string => $memo->title);
    }

    /** @throws Exception */
    public function testATransformableComparesEqualToNil(): void
    {
        $this->assertSame(["empty"], $this->titlesMatching(Predicate::format("note == nil"))->array);
    }

    /** @throws Exception */
    public function testATransformableComparesNotEqualToNil(): void
    {
        $this->assertSame(["draft"], $this->titlesMatching(Predicate::format("note != nil"))->array);
    }

    /** @throws Exception */
    public function testATransformableRefusesEqualityAgainstAValue(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/\"Memo\.note\" is transformable/");
        $this->titlesMatching(Predicate::format("note == %@", new ArrayClass([self::Note])));
    }

    /** @throws Exception */
    public function testATransformableRefusesAnyOtherOperator(): void
    {
        $this->expectException(InternalInconsistencyException::class);
        $this->expectExceptionMessageMatches("/\"Memo\.extras\" is transformable/");
        $this->titlesMatching(Predicate::format("extras CONTAINS %@", new ArrayClass(["priority"])));
    }
}
