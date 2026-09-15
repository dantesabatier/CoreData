<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Exception;
use Override;
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
use Sabatier\Foundation\Notification;
use Sabatier\Foundation\NotificationCenter;
use const Sabatier\CoreData\PersistentHistoryTokenKey;
use const Sabatier\CoreData\PersistentHistoryTrackingKey;
use const Sabatier\CoreData\PersistentStoreRemoteChange;
use const Sabatier\CoreData\PersistentStoreRemoteChangeNotificationPostOptionKey;
use const Sabatier\CoreData\PersistentStoreURLKey;
use const Sabatier\CoreData\StoreUUIDKey;

/**
 * @property string $serial
 */
final class RemoteWidget extends ManagedObject
{
}

/**
 * Covers the remote-change notification an SQL store posts after a write.
 *
 * It is how one process learns another has written to the store it shares, and it is opt-in:
 * the store posts it only when PersistentStoreRemoteChangeNotificationPostOptionKey is set. The
 * post is also skipped whenever the request already carries history tracking, since a tracked
 * write announces itself through the history instead — so the option alone is not enough, and a
 * suite that enables both would never reach this path.
 *
 * Needs a real server: the notification carries the transaction ID the write produced, and the
 * store checks that transaction actually exists before announcing it.
 */
final class SQLRemoteChangeNotificationTest extends SQLMigrationTestCase
{
    /** @var list<ManagedObjectContext> The stacks this test opened with the option on, released in tearDown. */
    private array $notifyingContexts = [];

    private static function model(): ManagedObjectModel
    {
        $serial = new AttributeDescription();
        $serial->name = "serial";
        $serial->type = AttributeType::string;

        $widget = new EntityDescription();
        $widget->name = "RemoteWidget";
        $widget->managedObjectClassName = RemoteWidget::class;
        $widget->properties = new ArrayClass([$serial]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$widget]);
        return $model;
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->notifyingContexts as $context) {
            $context->persistentStoreCoordinator = null;
        }
        $this->notifyingContexts = [];
        parent::tearDown();
    }

    /**
     * A stack over the test database with remote-change posting enabled. The base class opens
     * its stacks without options, so this one is built here.
     *
     * @throws Exception
     */
    private function notifyingContext(): ManagedObjectContext
    {
        $coordinator = new PersistentStoreCoordinator(self::model());
        // History tracking is what produces the transaction ID: without it insertTransactionForRequestContext returns 0 and the guard rejects the write as having nothing to announce. The store still posts, because hasHistoryTracking is a property of the REQUEST — only a history request sets it — so an ordinary save over a history-tracking store satisfies both halves.
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $this->storeURL, new Dictionary([
            PersistentStoreRemoteChangeNotificationPostOptionKey => true,
            PersistentHistoryTrackingKey => true,
        ]));
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->notifyingContexts[] = $context;
        return $context;
    }

    /**
     * A write announces itself, and the notification carries what a listening process needs to
     * identify the store and pick up from the right point in its history.
     *
     * @throws Exception
     */
    public function testAWriteAnnouncesItselfWithTheStoreIdentityAndHistoryToken(): void
    {
        $this->bootstrap(self::model());
        $context = $this->notifyingContext();

        /** @var Dictionary<mixed>|null $userInfo */
        $userInfo = null;
        $observer = NotificationCenter::default()->addObserverForName(
            PersistentStoreRemoteChange,
            null,
            function (Notification $notification) use (&$userInfo): void {
                $userInfo = $notification->userInfo;
            },
        );

        $widget = new RemoteWidget($context);
        $widget->serial = "S-1";
        $context->save();

        NotificationCenter::default()->removeObserver($observer);

        $this->assertNotNull($userInfo, "the write posts a remote-change notification");
        $this->assertNotNull($userInfo[StoreUUIDKey], "the notification names the store that changed");
        $this->assertNotNull($userInfo[PersistentStoreURLKey], "and where it lives");
        $this->assertNotNull($userInfo[PersistentHistoryTokenKey], "and the token a listener resumes from");
    }

    /**
     * A store that tracks history but did not ask for the notification stays silent, which is
     * what makes the previous test about the option rather than about saving at all.
     *
     * History tracking has to stay ON here. Dropping it would leave the store silent for the
     * other reason — no transaction ID to announce — and the test would pass with the opt-in
     * check removed entirely, which is exactly what it is meant to catch. Measured: with a
     * plain store, deleting the option from the guard left this green.
     *
     * @throws Exception
     */
    public function testAStoreWithoutTheOptionPostsNothing(): void
    {
        $this->bootstrap(self::model());
        $coordinator = new PersistentStoreCoordinator(self::model());
        $coordinator->addPersistentStoreWithType(PersistentStoreType::sql, null, $this->storeURL, new Dictionary([
            PersistentHistoryTrackingKey => true,
        ]));
        $context = new ManagedObjectContext();
        $context->persistentStoreCoordinator = $coordinator;
        $this->notifyingContexts[] = $context;

        $posted = false;
        $observer = NotificationCenter::default()->addObserverForName(
            PersistentStoreRemoteChange,
            null,
            function () use (&$posted): void {
                $posted = true;
            },
        );

        $widget = new RemoteWidget($context);
        $widget->serial = "S-2";
        $context->save();

        NotificationCenter::default()->removeObserver($observer);

        $this->assertFalse($posted, "a store that did not opt in announces nothing");
    }
}
