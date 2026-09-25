<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Set;

/**
 * @property Set<SQLTenant> $accounts
 * @property string $name
 * @method void addAccountsObject(SQLTenant $object)
 * @method void removeAccountsObject(SQLTenant $object)
 * @method void addAccounts(Set<SQLTenant> $objects)
 * @method void removeAccounts(Set<SQLTenant> $objects)
 * @method Set<SQLTenant> intersectAccounts(Set<SQLTenant> $objects)
 * @method void setAccounts(Set<SQLTenant> $objects)
 */
final class SQLGrant extends ManagedObject
{
}

/**
 * @property string $name
 * @property Set<SQLGrant> $permissions
 * @method void addPermissionsObject(SQLGrant $object)
 * @method void removePermissionsObject(SQLGrant $object)
 * @method void addPermissions(Set<SQLGrant> $objects)
 * @method void removePermissions(Set<SQLGrant> $objects)
 * @method Set<SQLGrant> intersectPermissions(Set<SQLGrant> $objects)
 * @method void setPermissions(Set<SQLGrant> $objects)
 */
final class SQLTenant extends ManagedObject
{
}

/**
 * Updating a many-to-many relationship against a REAL SQL store (MariaDB), where the links
 * live in a correlation table written through SQLCorrelationTableUpdateTracker.
 *
 * ClearToManyRelationshipTest and PartialToManyRelationshipUpdateTest cover the same
 * behaviour over the XML store. That is a different persistence path: the XML store rewrites
 * a document, while the SQL store has to emit INSERT/DELETE against a join table. A change
 * that works in XML can still be lost here, so the SQL path needs its own coverage — this is
 * the path a Sabatier Service application (and Singularity itself) actually runs on.
 *
 * Reuses SQLMigrationTestCase purely for its database lifecycle (temporary .env, drop/create
 * around each test, bootstrap/freshContext helpers); no migration is exercised.
 */
final class SQLToManyRelationshipUpdateTest extends SQLMigrationTestCase
{
    /**
     * SQLTenant <-> SQLGrant many-to-many, the shape of AccessControl <-> Role.
     */
    private static function makeModel(): ManagedObjectModel
    {
        $accountName = new AttributeDescription();
        $accountName->name = "name";
        $accountName->type = AttributeType::string;

        $permissions = new RelationshipDescription();
        $permissions->name = "permissions";
        $permissions->lazyDestinationEntityName = "SQLGrant";
        $permissions->lazyInverseRelationshipName = "accounts";
        $permissions->isToMany = true;

        $account = new EntityDescription();
        $account->name = "SQLTenant";
        $account->managedObjectClassName = SQLTenant::class;
        $account->properties = new ArrayClass([$accountName, $permissions]);

        $permissionName = new AttributeDescription();
        $permissionName->name = "name";
        $permissionName->type = AttributeType::string;

        $accounts = new RelationshipDescription();
        $accounts->name = "accounts";
        $accounts->lazyDestinationEntityName = "SQLTenant";
        $accounts->lazyInverseRelationshipName = "permissions";
        $accounts->isToMany = true;

        $permission = new EntityDescription();
        $permission->name = "SQLGrant";
        $permission->managedObjectClassName = SQLGrant::class;
        $permission->properties = new ArrayClass([$permissionName, $accounts]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$account, $permission]);
        return $model;
    }

    /**
     * An SQLTenant holding the three named Permissions, saved to the database.
     *
     * @return array{0: SQLTenant, 1: Dictionary<SQLGrant>}
     * @throws Exception
     */
    private function makeSavedAccount(ManagedObjectContext $context, string ...$names): array
    {
        /** @var Dictionary<SQLGrant> $permissionsByName */
        $permissionsByName = new Dictionary();
        foreach ($names as $name) {
            $permission = new SQLGrant($context);
            $permission->name = $name;
            $permissionsByName[$name] = $permission;
        }
        $account = new SQLTenant($context);
        $account->name = "acme";
        $account->setValueForKey(new Set($permissionsByName->values), "permissions");
        $context->save();
        return [$account, $permissionsByName];
    }

    /**
     * @return list<string>
     */
    private function permissionNames(SQLTenant $account): array
    {
        $names = [];
        foreach ($account->permissions as $permission) {
            $names[] = $permission->name;
        }
        sort($names);
        return $names;
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    private function reloadedPermissionNames(): array
    {
        $account = $this->freshContext(self::makeModel())->fetch(SQLTenant::fetchRequest())->first;
        $this->assertNotNull($account, "the account is still in the database");
        return $this->permissionNames($account);
    }

    /** @throws Exception */
    public function testEmptyingPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account] = $this->makeSavedAccount($context, "read");
        $this->assertSame(["read"], $this->permissionNames($account), "precondition: one permission is attached");

        $account->setValueForKey(new Set(), "permissions");
        $context->save();

        $this->assertSame([], $this->permissionNames($account), "the relationship is empty in memory");
        $this->assertSame([], $this->reloadedPermissionNames(), "the correlation row was deleted in the database");
    }

    /** @throws Exception */
    public function testRemovingOneOfSeveralPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");
        $this->assertSame(["admin", "read", "write"], $this->permissionNames($account), "precondition: three permissions are attached");

        $account->setValueForKey(new Set([$permissions["read"], $permissions["admin"]]), "permissions");
        $context->save();

        $this->assertSame(["admin", "read"], $this->permissionNames($account), "the subset is applied in memory");
        $this->assertSame(["admin", "read"], $this->reloadedPermissionNames(), "only the removed correlation row was deleted");
    }

    /**
     * The route Sabatier Service takes when it applies a PATCH body: the client sends back the
     * references that remain.
     *
     * @throws Exception
     */
    public function testUpdateFromSnapshotWithASubsetPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $account->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "permissions" => new ArrayClass([$permissions["read"]->objectID, $permissions["admin"]->objectID]),
        ]));
        $context->save();

        $this->assertSame(["admin", "read"], $this->reloadedPermissionNames(), "the subset reached the correlation table");
    }

    /**
     * The shape a client actually sends: the relationship arrives as a list of plain rows keyed by
     * objectID, not as ManagedObject/ManagedObjectID instances, and the request carries a
     * serialization that includes the relationship. Removing one entry has to delete exactly its
     * correlation row and survive a re-fetch through the same context.
     *
     * @throws Exception
     */
    public function testUpdateFromSnapshotWithScalarRowsRemovesOnlyTheOmittedOne(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $serialization = Dictionary::dictionaryWithArray([
            "name" => AttributeType::string,
            "permissions" => ["name" => AttributeType::string],
        ]);

        $account->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "objectID" => $account->objectID->referenceObject,
            "name" => $account->name,
            "permissions" => [
                ["objectID" => $permissions["read"]->objectID->referenceObject],
                ["objectID" => $permissions["admin"]->objectID->referenceObject],
            ],
        ]));
        $context->save();

        $this->assertSame(["admin", "read"], $this->permissionNames($account), "the omitted row is gone in memory");

        $request = SQLTenant::fetchRequest();
        $request->serialization = $serialization;
        $reFetched = $context->fetch($request)->first;
        $this->assertNotNull($reFetched, "the account is still reachable");
        $this->assertSame(["admin", "read"], $this->permissionNames($reFetched), "and it does not come back on a re-fetch");
        $this->assertSame(["admin", "read"], $this->reloadedPermissionNames(), "the correlation row was deleted in the database");
    }

    /**
     * A snapshot that carries only the relationship must not blank out the attributes it omits.
     *
     * @throws Exception
     */
    public function testUpdateFromSnapshotWithOnlyARelationshipKeepsOtherAttributes(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write");

        $account->updateFromSnapshot(Dictionary::dictionaryWithArray([
            "permissions" => [
                ["objectID" => $permissions["read"]->objectID->referenceObject],
            ],
        ]));
        $context->save();

        $this->assertSame(["read"], $this->reloadedPermissionNames(), "the relationship was applied");
        $reloaded = $this->freshContext(self::makeModel())->fetch(SQLTenant::fetchRequest())->first;
        $this->assertSame("acme", (string)$reloaded->name, "an attribute absent from the snapshot is left alone");
    }

    /** @throws Exception */
    public function testUpdateFromSnapshotWithAnEmptyArrayClassPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account] = $this->makeSavedAccount($context, "read", "write");

        $account->updateFromSnapshot(Dictionary::dictionaryWithArray(["permissions" => new ArrayClass()]));
        $context->save();

        $this->assertSame([], $this->reloadedPermissionNames(), "every correlation row was deleted");
    }

    /** @throws Exception */
    public function testRemovingOneAtATimeAcrossSavesIsCumulative(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $account->setValueForKey(new Set([$permissions["read"], $permissions["write"]]), "permissions");
        $context->save();
        $this->assertSame(["read", "write"], $this->reloadedPermissionNames(), "the first removal reached the database");

        $account->setValueForKey(new Set([$permissions["read"]]), "permissions");
        $context->save();

        $this->assertSame(["read"], $this->reloadedPermissionNames(), "the second removal reached the database too");
    }

    // --- FaultingSetMutationMethods: the generated accessors application code actually calls ---

    /** @throws Exception */
    public function testRemovePermissionsObjectPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $account->removePermissionsObject($permissions["write"]);
        $context->save();

        $this->assertSame(["admin", "read"], $this->permissionNames($account), "the object left the relationship in memory");
        $this->assertSame(["admin", "read"], $this->reloadedPermissionNames(), "and its correlation row was deleted");
    }

    /** @throws Exception */
    public function testAddPermissionsObjectPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account] = $this->makeSavedAccount($context, "read");
        $audit = new SQLGrant($context);
        $audit->name = "audit";

        $account->addPermissionsObject($audit);
        $context->save();

        $this->assertSame(["audit", "read"], $this->reloadedPermissionNames(), "the added correlation row reached the database");
    }

    /** @throws Exception */
    public function testRemovePermissionsWithASetPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $account->removePermissions(new Set([$permissions["write"], $permissions["admin"]]));
        $context->save();

        $this->assertSame(["read"], $this->permissionNames($account), "both objects left the relationship");
        $this->assertSame(["read"], $this->reloadedPermissionNames(), "both correlation rows were deleted");
    }

    /** @throws Exception */
    public function testAddPermissionsWithASetPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account] = $this->makeSavedAccount($context, "read");
        $audit = new SQLGrant($context);
        $audit->name = "audit";
        $billing = new SQLGrant($context);
        $billing->name = "billing";

        $account->addPermissions(new Set([$audit, $billing]));
        $context->save();

        $this->assertSame(["audit", "billing", "read"], $this->reloadedPermissionNames(), "both correlation rows were written");
    }

    /** @throws Exception */
    public function testIntersectPermissionsPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $account->intersectPermissions(new Set([$permissions["read"], $permissions["admin"]]));
        $context->save();

        $this->assertSame(["admin", "read"], $this->permissionNames($account), "only the intersection survived in memory");
        $this->assertSame(["admin", "read"], $this->reloadedPermissionNames(), "the correlation table matches the intersection");
    }

    /** @throws Exception */
    public function testSetPermissionsPersistsToTheCorrelationTable(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $account->setPermissions(new Set([$permissions["read"]]));
        $context->save();

        $this->assertSame(["read"], $this->reloadedPermissionNames(), "the assigned set reached the correlation table");
    }

    /**
     * The generated accessors have to accumulate across saves too, the same way setValueForKey does.
     *
     * @throws Exception
     */
    public function testRemovePermissionsObjectAcrossSavesIsCumulative(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write", "admin");

        $account->removePermissionsObject($permissions["admin"]);
        $context->save();
        $this->assertSame(["read", "write"], $this->reloadedPermissionNames(), "the first removal reached the database");

        $account->removePermissionsObject($permissions["write"]);
        $context->save();
        $this->assertSame(["read"], $this->reloadedPermissionNames(), "the second removal reached the database too");
    }

    /** @throws Exception */
    public function testAssigningASetThatBothAddsAndRemovesPersists(): void
    {
        $context = $this->bootstrap(self::makeModel());
        [$account, $permissions] = $this->makeSavedAccount($context, "read", "write");
        $audit = new SQLGrant($context);
        $audit->name = "audit";

        $account->setValueForKey(new Set([$permissions["read"], $audit]), "permissions");
        $context->save();

        $this->assertSame(["audit", "read"], $this->reloadedPermissionNames(), "the added and removed rows are both handled");
    }
}
