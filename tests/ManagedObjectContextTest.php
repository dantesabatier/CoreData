<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use PHPUnit\Framework\TestCase;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\DeleteRule;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\MergePolicy;
use Sabatier\CoreData\PersistentStoreCoordinator;
use Sabatier\CoreData\PersistentStoreType;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\CoreData\XMLObjectStore;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Date;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Predicates\Predicate;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

final class Employee extends ManagedObject
{
}

final class Company extends ManagedObject
{
}

final class Department extends ManagedObject
{
}

final class Worker extends ManagedObject
{
}

final class Ticket extends ManagedObject
{
}

final class Page extends ManagedObject
{
}

final class AutoTicket extends ManagedObject
{
    #[\Override]
    public function willSave(): void
    {
        if ($this->isDeleted) {
            return;
        }
        // Populate a value just before persisting, the way a real subclass fills in a
        // creation date, a UUID, or a slug. Whatever willSave writes must be the value that
        // reaches validation and the store, which only holds if validation runs after willSave.
        if (!$this->valueForKey("code")) {
            $this->setValueForKey("AUTO-1", "code");
        }
    }
}

/**
 * Tests for src/ManagedObjectContext.php against a full stack
 * (ManagedObjectModel -> PersistentStoreCoordinator -> XMLObjectStore).
 *
 * The XML store is used because it is the only file-less-friendly backend that is
 * fully implemented (MemoryObjectStore does not implement load and cannot be added
 * to a coordinator). Behavior pinned down here:
 *  - insert/update/delete tracking: insertedObjects immediately, updatedObjects
 *    after processPendingChanges, deletedObjects immediately;
 *  - save() persists inserts and deletes, and clears the tracking sets;
 *  - typed attributes (string, integer, date, uuid) and default values survive a
 *    full round trip through a freshly-built stack reading the same file;
 *  - values assigned to typed attributes are coerced (numeric string -> int);
 *  - fetch() honors the request predicate;
 *  - rollback() discards pending insertions and resets hasChanges;
 *  - to-many relationships persist and can be traversed in both directions.
 */
final class ManagedObjectContextTest extends TestCase
{
    private string $storePath;
    private URL $storeURL;

    /**
     * Every stack that reads the same store file needs its own freshly-built model:
     * entity descriptions are frozen once assembled and bound to their coordinator.
     */
    private static function makeCompanyModel(): ManagedObjectModel
    {
        $employeeName = new AttributeDescription();
        $employeeName->name = "name";
        $employeeName->type = AttributeType::string;

        $salary = new AttributeDescription();
        $salary->name = "salary";
        $salary->type = AttributeType::integer32;
        $salary->defaultValue = 1000;

        $hired = new AttributeDescription();
        $hired->name = "hired";
        $hired->type = AttributeType::date;

        $badge = new AttributeDescription();
        $badge->name = "badge";
        $badge->type = AttributeType::uuid;

        $employer = new RelationshipDescription();
        $employer->name = "employer";
        $employer->lazyDestinationEntityName = "Company";
        $employer->lazyInverseRelationshipName = "employees";
        $employer->maxCount = 1;

        $employee = new EntityDescription();
        $employee->name = "Employee";
        $employee->managedObjectClassName = Employee::class;
        $employee->properties = new ArrayClass([$employeeName, $salary, $hired, $badge, $employer]);

        $companyName = new AttributeDescription();
        $companyName->name = "name";
        $companyName->type = AttributeType::string;

        $employees = new RelationshipDescription();
        $employees->name = "employees";
        $employees->lazyDestinationEntityName = "Employee";
        $employees->lazyInverseRelationshipName = "employer";
        $employees->isToMany = true;

        $company = new EntityDescription();
        $company->name = "Company";
        $company->managedObjectClassName = Company::class;
        $company->properties = new ArrayClass([$companyName, $employees]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$employee, $company]);
        return $model;
    }

    private function makeContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makeCompanyModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    private function insertEmployee(ManagedObjectContext $context, string $name, ?int $salary = null): Employee
    {
        $employee = new Employee($context);
        $employee->name = $name;
        if ($salary !== null) {
            $employee->salary = $salary;
        }
        return $employee;
    }

    /** @return FetchRequest<Employee> */
    private static function requestForName(string $name): FetchRequest
    {
        $request = Employee::fetchRequest();
        $request->predicate = Predicate::format("name == %s", new ArrayClass([$name]));
        return $request;
    }

    protected function setUp(): void
    {
        $this->storePath = sys_get_temp_dir() . "/coredata-context-test-" . uniqid("", true) . ".xml";
        $this->storeURL = new URL("file:///" . str_replace("\\", "/", $this->storePath));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storePath)) {
            unlink($this->storePath);
        }
    }

    public function testCoordinatorMaterializesAnXMLObjectStore(): void
    {
        $context = $this->makeContext();

        $this->assertInstanceOf(XMLObjectStore::class, $context->persistentStoreCoordinator->persistentStores->first());
        $this->assertFalse($context->hasChanges, "a fresh context has no changes");
    }

    public function testInsertTracking(): void
    {
        $context = $this->makeContext();
        $alice = $this->insertEmployee($context, "Alice", 2000);

        $this->assertCount(1, $context->insertedObjects, "the new object is tracked in insertedObjects");
        $this->assertTrue($context->insertedObjects->containsElement($alice), "insertedObjects contains the new object");
        $this->assertTrue($context->hasChanges, "an insertion marks the context as changed");
        $this->assertTrue($alice->changedValues()->offsetExists("name"), "the assigned attribute shows up in changedValues");
        $this->assertSame($context, $alice->managedObjectContext, "the object is bound to its context");
        $this->assertSame("Employee", $alice->entity->name, "the object carries its entity description");
    }

    public function testUnassignedAttributeReportsItsModelDefaultValue(): void
    {
        $context = $this->makeContext();
        $bob = $this->insertEmployee($context, "Bob");

        $this->assertSame(1000, (int)$bob->salary);
    }

    public function testSavePersistsAndClearsTracking(): void
    {
        $context = $this->makeContext();
        $alice = $this->insertEmployee($context, "Alice", 2000);

        $this->assertTrue($context->save(), "save reports success");
        $this->assertCount(0, $context->insertedObjects, "save clears insertedObjects");
        $this->assertFalse($context->hasChanges, "save resets hasChanges");
        $this->assertFileExists($this->storePath, "save writes the store file");
        $this->assertFalse($alice->objectID->isTemporaryID, "the saved object has a permanent ID");
    }

    public function testFetchHonorsThePredicate(): void
    {
        $context = $this->makeContext();
        $this->insertEmployee($context, "Alice", 2000);
        $this->insertEmployee($context, "Bob");
        $context->save();

        $this->assertCount(2, $context->fetch(Employee::fetchRequest()), "fetching all employees returns both saved objects");

        $matched = $context->fetch(self::requestForName("Alice"));
        $this->assertCount(1, $matched, "a predicate narrows the fetch to the matching object");
        $this->assertSame("Alice", (string)$matched->first()->name, "the predicate matched the right object");

        $this->assertCount(0, $context->fetch(self::requestForName("Nobody")), "a predicate matching nothing returns an empty result");
    }

    public function testUpdateTracking(): void
    {
        $context = $this->makeContext();
        $alice = $this->insertEmployee($context, "Alice", 2000);
        $context->save();

        $alice->salary = 2500;
        $context->processPendingChanges();
        $this->assertCount(1, $context->updatedObjects, "processPendingChanges promotes the change into updatedObjects");
        $this->assertTrue($context->updatedObjects->containsElement($alice), "updatedObjects contains the modified object");
        $this->assertTrue($context->hasChanges, "a pending update marks the context as changed");

        $this->assertTrue($context->save(), "saving the update reports success");
        $this->assertCount(0, $context->updatedObjects, "save clears updatedObjects");
        $this->assertSame(2500, $alice->salary, "the context keeps the updated value after save");

        // The update must reach the store: a freshly-built stack reading the same file sees 2500, not the committed-at-insert 2000.
        $rereadContext = $this->makeContext();
        $reloaded = $rereadContext->fetch(self::requestForName("Alice"))->first();
        $this->assertNotNull($reloaded, "the updated object is visible to a freshly-built stack");
        $this->assertSame(2500, $reloaded->salary, "the updated value survives a reload through a fresh coordinator/context stack");
    }

    public function testTypedAttributesSurviveARoundTripThroughAFreshStack(): void
    {
        $context = $this->makeContext();
        $alice = $this->insertEmployee($context, "Alice", 2000);
        $alice->hired = Date::dateWithTimeIntervalSince1970(1700000000.0);
        $alice->badge = new UUID("E621E1F8-C36C-495A-93FC-0C247A3E6E5F");
        $this->insertEmployee($context, "Bob");
        $context->save();

        $rereadContext = $this->makeContext();
        $reloaded = $rereadContext->fetch(self::requestForName("Alice"))->first();

        $this->assertNotNull($reloaded, "the saved object is visible to a freshly-built stack");
        $this->assertSame("Alice", (string)$reloaded->name, "the string attribute survives the round trip");
        $this->assertSame(2000, $reloaded->salary, "the integer attribute survives the round trip");
        $this->assertInstanceOf(Date::class, $reloaded->hired, "the date attribute is materialized as a Foundation Date");
        $this->assertEqualsWithDelta(1700000000.0, $reloaded->hired->timeIntervalSince1970, 1.0, "the date attribute keeps its instant");
        $this->assertInstanceOf(UUID::class, $reloaded->badge, "the uuid attribute is materialized as a Foundation UUID");
        $this->assertSame("E621E1F8-C36C-495A-93FC-0C247A3E6E5F", $reloaded->badge->uuidString, "the uuid attribute keeps its value");

        $rereadBob = $rereadContext->fetch(self::requestForName("Bob"))->first();
        $this->assertNotNull($rereadBob);
        $this->assertSame(1000, $rereadBob->salary, "the default value was persisted for the unassigned attribute");
    }

    public function testNumericStringsAreCoercedIntoIntegerAttributes(): void
    {
        $context = $this->makeContext();
        $alice = $this->insertEmployee($context, "Alice");

        $alice->salary = "3000";
        $this->assertSame(3000, $alice->salary);
    }

    public function testRollbackDiscardsPendingInsertions(): void
    {
        $context = $this->makeContext();
        $this->insertEmployee($context, "Alice", 2000);
        $context->save();

        $this->insertEmployee($context, "Carol");
        $this->assertCount(1, $context->insertedObjects, "the pending insertion is tracked");

        $context->rollback();
        $this->assertCount(0, $context->insertedObjects, "rollback discards the pending insertion");
        $this->assertFalse($context->hasChanges, "rollback resets hasChanges");
        $this->assertCount(1, $context->fetch(Employee::fetchRequest()), "the rolled-back object never reaches the store");
    }

    /**
     * Regression: rollback() restores updated objects to their last committed values. Mutating a saved
     * object and rolling back used to leave the mutated value in memory (the store and committed snapshot
     * held the old one), so the object reported the uncommitted value and a follow-up save would have
     * written it. rollback() must revert the attribute and clear change tracking so the follow-up save is
     * a no-op. See "rollback does not restore committed values".
     */
    public function testRollbackRestoresUpdatedObjectsToCommittedValues(): void
    {
        $context = $this->makeContext();
        $alice = $this->insertEmployee($context, "Alice", 2000);
        $context->save();

        $alice->salary = 2500;
        $context->processPendingChanges();
        $this->assertSame(2500, $alice->salary, "the mutation is visible before rollback");
        $this->assertTrue($context->updatedObjects->containsElement($alice), "the mutated object is tracked as updated");

        $context->rollback();

        $this->assertSame(2000, $alice->salary, "rollback restores the attribute to its last committed value");
        $this->assertFalse($alice->isUpdated, "the rolled-back object no longer reports unsaved changes");
        $this->assertFalse($context->hasChanges, "rollback leaves the context with no pending changes");
        $this->assertCount(0, $context->updatedObjects, "rollback clears the updatedObjects tracking set");

        // A follow-up save must write nothing new: a freshly-built stack reading the same file still sees 2000.
        $this->assertTrue($context->save(), "a save after rollback reports success");
        $rereadContext = $this->makeContext();
        $reloaded = $rereadContext->fetch(self::requestForName("Alice"))->first();
        $this->assertNotNull($reloaded, "the object is still in the store");
        $this->assertSame(2000, $reloaded->salary, "the follow-up save wrote nothing new: the committed value stands");
    }

    public function testDeletePersistsAndClearsTracking(): void
    {
        $context = $this->makeContext();
        $this->insertEmployee($context, "Alice", 2000);
        $bob = $this->insertEmployee($context, "Bob");
        $context->save();

        $context->delete($bob);
        $this->assertCount(1, $context->deletedObjects, "the object is tracked in deletedObjects");
        $this->assertTrue($bob->isDeleted, "the object reports isDeleted");

        $this->assertTrue($context->save(), "saving the deletion reports success");
        $this->assertCount(0, $context->deletedObjects, "save clears deletedObjects");
        $this->assertCount(1, $context->fetch(Employee::fetchRequest()), "the deleted object is gone from the store");
    }

    public function testToManyRelationshipsPersistAndTraverseBothWays(): void
    {
        $context = $this->makeContext();
        $acme = new Company($context);
        $acme->name = "Acme";
        $dave = $this->insertEmployee($context, "Dave");
        $acme->mutableSetValueForKey("employees")->insert($dave);

        $this->assertTrue($context->save(), "saving the related objects reports success");

        $employees = $context->fetch(Company::fetchRequest())->first()->employees;
        $this->assertNotNull($employees, "the to-many relationship persists its members");
        $this->assertCount(1, $employees);
        $this->assertSame("Dave", (string)$employees->first()->name, "the to-many relationship resolves the related object");

        $davesEmployer = $context->fetch(self::requestForName("Dave"))->first()->employer;
        $this->assertNotNull($davesEmployer, "the inverse to-one relationship resolves after a fetch");
        $this->assertSame("Acme", (string)$davesEmployer->name);
    }

    /**
     * Regression: deleting a Department cascaded to its Workers, and persisting that in
     * the XML store used to throw DOMException "Not Found Error" — the context and the
     * store both walked the cascade, so the store tried to removeChild an element that
     * had already been detached. See the "Cascade delete crashes the XML store" note.
     */
    public function testCascadeDeleteRemovesRelatedObjectsFromTheStore(): void
    {
        // A dedicated model with cascadeDeleteRule, on its own ManagedObject subclasses
        // so it does not clobber the shared nullify Company/Employee registration.
        $context = $this->makeCascadeContext();

        $engineering = new Department($context);
        $engineering->name = "Engineering";
        $engineering->mutableSetValueForKey("workers")->insert($this->insertWorker($context, "Ann"));
        $engineering->mutableSetValueForKey("workers")->insert($this->insertWorker($context, "Ben"));
        $this->assertTrue($context->save(), "saving the department and its workers reports success");
        $this->assertCount(2, $context->fetch(Worker::fetchRequest()), "both workers are persisted");

        $context->delete($engineering);
        $this->assertTrue($context->save(), "the cascade delete saves without throwing");
        $this->assertCount(0, $context->fetch(Department::fetchRequest()), "the department is gone");
        $this->assertCount(0, $context->fetch(Worker::fetchRequest()), "the cascade removed its workers");

        // The failure was a DOM-level desync, so confirm it survives a fresh stack too.
        $rereadContext = $this->makeCascadeContext();
        $this->assertCount(0, $rereadContext->fetch(Worker::fetchRequest()), "the cascade is persisted, not just in-memory");
    }

    private static function makeCascadeModel(): ManagedObjectModel
    {
        $departmentName = new AttributeDescription();
        $departmentName->name = "name";
        $departmentName->type = AttributeType::string;

        $workers = new RelationshipDescription();
        $workers->name = "workers";
        $workers->lazyDestinationEntityName = "Worker";
        $workers->lazyInverseRelationshipName = "department";
        $workers->isToMany = true;
        $workers->deleteRule = DeleteRule::cascadeDeleteRule;

        $department = new EntityDescription();
        $department->name = "Department";
        $department->managedObjectClassName = Department::class;
        $department->properties = new ArrayClass([$departmentName, $workers]);

        $workerName = new AttributeDescription();
        $workerName->name = "name";
        $workerName->type = AttributeType::string;

        $departmentRelationship = new RelationshipDescription();
        $departmentRelationship->name = "department";
        $departmentRelationship->lazyDestinationEntityName = "Department";
        $departmentRelationship->lazyInverseRelationshipName = "workers";
        $departmentRelationship->maxCount = 1;

        $worker = new EntityDescription();
        $worker->name = "Worker";
        $worker->managedObjectClassName = Worker::class;
        $worker->properties = new ArrayClass([$workerName, $departmentRelationship]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$department, $worker]);
        return $model;
    }

    private function makeCascadeContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makeCascadeModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    private function insertWorker(ManagedObjectContext $context, string $name): Worker
    {
        $worker = new Worker($context);
        $worker->name = $name;
        return $worker;
    }

    /**
     * A Ticket has a mandatory "code" (isOptional=false, no default), a mandatory "priority"
     * with a defaultValue, and an optional "note". Enough to pin down scalar optionality
     * enforcement without disturbing the shared Company/Employee registration.
     */
    private static function makeTicketModel(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;
        $code->isOptional = false;

        $priority = new AttributeDescription();
        $priority->name = "priority";
        $priority->type = AttributeType::integer32;
        $priority->isOptional = false;
        $priority->defaultValue = 3;
        $priority->minValue = 1;
        $priority->maxValue = 5;

        $note = new AttributeDescription();
        $note->name = "note";
        $note->type = AttributeType::string;

        $ticket = new EntityDescription();
        $ticket->name = "Ticket";
        $ticket->managedObjectClassName = Ticket::class;
        $ticket->properties = new ArrayClass([$code, $priority, $note]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$ticket]);
        return $model;
    }

    private function makeTicketContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makeTicketModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * A non-optional scalar attribute left unset is supplied by the framework with its type's
     * default value (string -> "", integer -> 0, and so on) rather than rejected: providing the
     * value for a mandatory attribute wherever possible is the framework's job (in SQL this is
     * enforced at the DDL level). The coerced value is then subject to the other validation
     * rules (length, range, regex, uniqueness), but optionality alone does not fail the save.
     */
    public function testUnsetMandatoryAttributeIsFilledWithItsTypeDefault(): void
    {
        $context = $this->makeTicketContext();
        $ticket = new Ticket($context); // "code" (mandatory string, no default) left unset

        $this->assertTrue($context->save(), "the save succeeds; the framework supplies the mandatory value");
        $this->assertSame("", (string)$ticket->code, "the mandatory string was filled with its type default");

        $rereadContext = $this->makeTicketContext();
        $reloaded = $rereadContext->fetch(Ticket::fetchRequest())->first();
        $this->assertNotNull($reloaded, "the object reached the store");
        $this->assertSame("", (string)$reloaded->code, "the filled default was persisted");
    }

    /**
     * The framework fills a mandatory attribute, but the coerced value is still subject to the
     * property's other validation rules. A priority outside its [1, 5] range fails the save — not
     * because the attribute is mandatory, but because the range rule rejects the value. This is
     * the level the framework honors: supply the value, then validate it against length, range,
     * regex, and so on.
     */
    public function testAFilledValueMustStillSatisfyTheOtherValidationRules(): void
    {
        $context = $this->makeTicketContext();
        $ticket = new Ticket($context);
        $ticket->code = "ABC-9";
        $ticket->priority = 99; // outside the modeled [1, 5] range

        $this->expectException(InternalInconsistencyException::class);
        $context->save();
    }

    public function testSaveSucceedsOnceTheRequiredAttributeIsSet(): void
    {
        $context = $this->makeTicketContext();
        $ticket = new Ticket($context);
        $ticket->code = "ABC-1";

        $this->assertTrue($context->save(), "save succeeds once the mandatory attribute has a value");

        $rereadContext = $this->makeTicketContext();
        $reloaded = $rereadContext->fetch(Ticket::fetchRequest())->first();
        $this->assertNotNull($reloaded, "the object reached the store");
        $this->assertSame("ABC-1", (string)$reloaded->code, "the mandatory value round-trips");
    }

    /**
     * A non-optional attribute with a defaultValue must not fail: the default supplies the value.
     */
    public function testSaveSucceedsWhenARequiredAttributeHasADefaultValue(): void
    {
        $context = $this->makeTicketContext();
        $ticket = new Ticket($context);
        $ticket->code = "ABC-2"; // "priority" (required, default 3) is left unset on purpose

        $this->assertTrue($context->save(), "the default value satisfies the mandatory priority attribute");
        $this->assertSame(3, $ticket->priority, "the default value was applied to the unassigned mandatory attribute");
    }

    /**
     * Updating an unrelated attribute of an already-valid object keeps the save valid: the
     * mandatory "code" set on insert is untouched.
     */
    public function testUpdatingAnUnrelatedAttributeKeepsTheSaveValid(): void
    {
        $context = $this->makeTicketContext();
        $ticket = new Ticket($context);
        $ticket->code = "ABC-3";
        $this->assertTrue($context->save(), "the initial insert is valid");

        $ticket->note = "follow up"; // does not touch the mandatory "code"
        $context->processPendingChanges();

        $this->assertTrue($context->save(), "updating an unrelated attribute keeps the save valid");
    }

    /**
     * Regression: validation and persistence must see the state left by willSave(). A subclass
     * fills in a value in its willSave() hook (a creation date, a UUID, a computed slug) just
     * before persisting; that value — not the framework's earlier type default — must be the one
     * validated and stored. This only holds because validation runs after willSave, matching Core
     * Data's willSave -> validateFor{Insert,Update} -> persist cycle.
     */
    public function testWillSaveValueReachesValidationAndTheStore(): void
    {
        $context = $this->makeAutoTicketContext();
        $autoTicket = new AutoTicket($context); // willSave() overwrites the filled "" with a real code

        $this->assertTrue($context->save(), "the save succeeds");
        $this->assertSame("AUTO-1", (string)$autoTicket->code, "willSave's value replaced the framework's type default");

        $rereadContext = $this->makeAutoTicketContext();
        $reloaded = $rereadContext->fetch(AutoTicket::fetchRequest())->first();
        $this->assertNotNull($reloaded, "the object reached the store");
        $this->assertSame("AUTO-1", (string)$reloaded->code, "the willSave-supplied value was persisted, not the type default");
    }

    private static function makeAutoTicketModel(): ManagedObjectModel
    {
        $code = new AttributeDescription();
        $code->name = "code";
        $code->type = AttributeType::string;
        $code->isOptional = false;

        $autoTicket = new EntityDescription();
        $autoTicket->name = "AutoTicket";
        $autoTicket->managedObjectClassName = AutoTicket::class;
        $autoTicket->properties = new ArrayClass([$code]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$autoTicket]);
        return $model;
    }

    private function makeAutoTicketContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makeAutoTicketModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        return $context;
    }

    /**
     * A Page entity whose "slug" is declared unique through the entity's uniquenessConstraints
     * (never through an explicit $indexes assignment). This is the shape the atomic (XML) store
     * used to ignore entirely.
     */
    private static function makePageModel(): ManagedObjectModel
    {
        $slug = new AttributeDescription();
        $slug->name = "slug";
        $slug->type = AttributeType::string;
        $slug->isOptional = false;

        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $page = new EntityDescription();
        $page->name = "Page";
        $page->managedObjectClassName = Page::class;
        $page->properties = new ArrayClass([$slug, $title]);
        $page->uniquenessConstraints = new ArrayClass([new ArrayClass(["slug"])]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$page]);
        return $model;
    }

    private function makePageContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::makePageModel());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::xml, null, $this->storeURL);
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        // The error merge policy is the context default, but pin it explicitly: these tests assert that a
        // constraint conflict raises rather than being silently resolved by a merging policy.
        $context->mergePolicy = MergePolicy::error();
        return $context;
    }

    private function insertPage(ManagedObjectContext $context, string $slug, string $title = "Untitled"): Page
    {
        $page = new Page($context);
        $page->slug = $slug;
        $page->title = $title;
        return $page;
    }

    /**
     * Regression: the XML/atomic store silently ignored EntityDescription::$uniquenessConstraints —
     * unlike the SQL backend, which rejects a duplicate at the DDL level. Two objects sharing a unique
     * value both saved, leaving two rows that violate the constraint. The pre-save constraint check
     * (ConflictDetectionService::detectConstraintConflicts) early-returned for freshly-inserted objects
     * because they carry no originalSnapshot, so the check never ran on inserts. Under the error merge
     * policy, saving a second object with a value already committed to the store must now fail.
     */
    public function testDuplicateUniqueValueAgainstAPersistedRowFails(): void
    {
        $context = $this->makePageContext();
        $this->insertPage($context, "home");
        $this->assertTrue($context->save(), "the first object with a unique slug saves");

        $this->insertPage($context, "home");
        $this->expectException(InternalInconsistencyException::class);
        $context->save();
    }

    /**
     * The in-memory side of the same save: two brand-new objects sharing a unique value in a single
     * save() are both pending and neither is in the store yet, so a store-only check would miss them.
     * The constraint must be enforced against the pending peers too.
     */
    public function testDuplicateUniqueValueAmongPendingInsertsInOneSaveFails(): void
    {
        $context = $this->makePageContext();
        $this->insertPage($context, "about");
        $this->insertPage($context, "about");

        $this->expectException(InternalInconsistencyException::class);
        $context->save();
    }

    /**
     * The comparison is case-insensitive, matching the LIKE predicate the store check uses for string
     * attributes, so "Home" collides with "home".
     */
    public function testUniqueValueComparisonIsCaseInsensitive(): void
    {
        $context = $this->makePageContext();
        $this->insertPage($context, "home");
        $this->assertTrue($context->save(), "the first slug saves");

        $this->insertPage($context, "HOME");
        $this->expectException(InternalInconsistencyException::class);
        $context->save();
    }

    /**
     * Distinct values must save without complaint: the constraint only fires on an actual duplicate.
     */
    public function testDistinctUniqueValuesSaveFine(): void
    {
        $context = $this->makePageContext();
        $this->insertPage($context, "home");
        $this->insertPage($context, "about");
        $this->insertPage($context, "contact");

        $this->assertTrue($context->save(), "three distinct slugs save together");

        $rereadContext = $this->makePageContext();
        $this->assertCount(3, $rereadContext->fetch(Page::fetchRequest()), "all three distinct pages reached the store");
    }

    /**
     * Updating an existing object to a slug already held by another persisted object also violates the
     * constraint — the check runs on updates as well as inserts.
     */
    public function testUpdatingToADuplicateUniqueValueFails(): void
    {
        $context = $this->makePageContext();
        $this->insertPage($context, "home");
        $about = $this->insertPage($context, "about");
        $this->assertTrue($context->save(), "two distinct pages save");

        $about->slug = "home";
        $context->processPendingChanges();

        $this->expectException(InternalInconsistencyException::class);
        $context->save();
    }
}
