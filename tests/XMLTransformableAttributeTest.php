<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
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
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

require_once __DIR__ . "/SQLTransformableAttributeTest.php";

/**
 * @property string|null $note
 * @property Dictionary<mixed>|null $extras
 */
final class Notebook extends ManagedObject
{
}

final class XMLTransformableAttributeTest extends TestCase
{
    private const string Note = "secret";

    private URL $storeURL;

    private static function model(): ManagedObjectModel
    {
        $note = new AttributeDescription();
        $note->name = "note";
        $note->type = AttributeType::transformable;
        $note->valueTransformerName = StrictNoteTransformer::class;

        $extras = new AttributeDescription();
        $extras->name = "extras";
        $extras->type = AttributeType::transformable;
        $extras->attributeValueClassName = Dictionary::class;

        $notebook = new EntityDescription();
        $notebook->name = "Notebook";
        $notebook->managedObjectClassName = Notebook::class;
        $notebook->properties = new ArrayClass([$note, $extras]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$notebook]);
        return $model;
    }

    /** @throws Exception */
    private function context(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    #[Override]
    protected function setUp(): void
    {
        $this->storeURL = FileManager::default()->temporaryDirectory->appendingPathComponent(new UUID()->uuidString)->appendingPathExtension("xml");
    }

    /** @throws Exception */
    #[Override]
    protected function tearDown(): void
    {
        FileManager::default()->removeItem($this->storeURL);
    }

    /** @throws Exception */
    private function seed(): void
    {
        $context = $this->context();
        $notebook = new Notebook($context);
        $notebook->note = self::Note;
        $notebook->extras = new Dictionary(["priority" => 2]);
        $context->save();
    }

    /** @throws Exception */
    public function testTheFileHoldsTheValueEncoded(): void
    {
        $this->seed();
        $contents = (string)file_get_contents($this->storeURL->path);
        $this->assertStringContainsString(StrictNoteTransformer::encoded(self::Note), $contents);
    }

    /** @throws Exception */
    public function testAReloadedObjectReadsTheValuesDecoded(): void
    {
        $this->seed();
        /** @var Notebook $notebook */
        $notebook = $this->context()->fetch(Notebook::fetchRequest())->first;
        $this->assertSame(self::Note, $notebook->note);
        $this->assertTrue(new Dictionary(["priority" => 2])->isEqual($notebook->extras));
    }
}
