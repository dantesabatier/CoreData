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
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\SQLConnection;
use Sabatier\CoreData\SQLCore;
use Sabatier\CoreData\SQLStatement;
use Sabatier\CoreData\SQLStoreRequestContext;
use Sabatier\Foundation\ArrayClass;

/**
 * @property string $name
 */
final class SQLRollbackProbe extends ManagedObject
{
}

/**
 * A failing write must surface the exception that actually failed, never one raised while
 * unwinding it.
 *
 * A write that fails while the connection is also gone hits this: the catch in
 * executeRequestUsingConnection() calls rollBack() to unwind, and rollBack() raises "MySQL
 * server has gone away" on top of the failure already travelling out. Before the fix the
 * unwinding exception replaced the original, so the log named the cleanup rather than the
 * cause — the same class of misdirection that reported a deadlock as error 1020 against
 * PersistentHistoryTransaction.
 *
 * SQLConnection::rollBack() guards on inTransaction() and so is safe when the server merely
 * closed the transaction, as a deadlock does. It is not safe when the connection itself is
 * dead: PDO still answers inTransaction() with true, so the guard passes and the rollback
 * raises. That is the case pinned here.
 *
 * SQLConnection is final and cannot be doubled, so this drives the real class against a real
 * database and kills the connection from a second one, the way a server-side timeout or an
 * operator's KILL does in production.
 *
 * Reuses SQLMigrationTestCase for its database lifecycle only; no migration is exercised.
 */
final class SQLRollbackFailureTest extends SQLMigrationTestCase
{
    private static function makeModel(): ManagedObjectModel
    {
        $name = new AttributeDescription();
        $name->name = "name";
        $name->type = AttributeType::string;

        $entity = new EntityDescription();
        $entity->name = "SQLRollbackProbe";
        $entity->managedObjectClassName = SQLRollbackProbe::class;
        $entity->properties = new ArrayClass([$name]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$entity]);
        return $model;
    }

    public function testTheOriginalFailureSurvivesAFailingRollback(): void
    {
        $context = $this->bootstrap(self::makeModel());
        /** @var SQLCore $sqlCore */
        $sqlCore = $context->persistentStoreCoordinator?->persistentStores->first;
        $this->assertNotNull($sqlCore, "the store stack did not come up");

        $requestContext = new class (new FetchRequest(), $context, $sqlCore) extends SQLStoreRequestContext {
            #[Override]
            public bool $isWritingRequest {
                get => true;
            }

            /**
             * Kills this connection from a second one, then fails. PDO goes on reporting an
             * active transaction, so the framework's guard passes and its rollBack() raises
             * over the exception already on its way out.
             */
            #[Override]
            protected function executeRequestCore(): bool
            {
                $connectionID = $this->connection->execute(new SQLStatement("SELECT CONNECTION_ID()"))->fetchColumn();
                $killer = new PDO("mysql:host=127.0.0.1", "root", null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $killer->exec("KILL $connectionID");
                usleep(200000);
                throw new RuntimeException("the statement that actually failed");
            }
        };

        try {
            $requestContext->executeRequestUsingConnection($sqlCore->queryGenerationTrackingConnection);
            $this->fail("the request context swallowed the failure");
        } catch (Exception $exception) {
            $this->assertSame("the statement that actually failed", $exception->getMessage(), "the exception raised while rolling back replaced the one that caused the failure");
        }
    }
}
