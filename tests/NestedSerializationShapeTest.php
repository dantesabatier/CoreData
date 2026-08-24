<?php

declare(strict_types=1);

namespace Sabatier\CoreData\Tests;

use Sabatier\CoreData\AttributeDescription;
use Sabatier\CoreData\AttributeType;
use Sabatier\CoreData\EntityDescription;
use Sabatier\CoreData\FetchRequest;
use Sabatier\CoreData\ManagedObject;
use Sabatier\CoreData\ManagedObjectContext;
use Sabatier\CoreData\ManagedObjectModel;
use Sabatier\CoreData\RelationshipDescription;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;

final class NSOrder extends ManagedObject
{
}

final class NSComment extends ManagedObject
{
}

final class NSUser extends ManagedObject
{
}

/**
 * A serialization shape must reach a relationship nested inside a to-many — Order -> comments -> user —
 * even for a child added after the parent was first prepared.
 *
 * The preparer memoizes the shape it has already applied to each object, and used to take an unchanged
 * shape as licence to stop walking. But an object whose own shape is satisfied can still have gained
 * children since: an update that appends to a to-many and then re-serializes the parent hands the second
 * pass a parent whose shape has not moved and a new child that has never seen it. The child was emitted
 * with the default keys — every attribute, no relationships — so the nested relationship silently
 * vanished from the response while its siblings kept theirs.
 */
final class NestedSerializationShapeTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $folio = new AttributeDescription();
        $folio->name = "folio";
        $folio->type = AttributeType::string;

        $comments = new RelationshipDescription();
        $comments->name = "comments";
        $comments->lazyDestinationEntityName = "NSComment";
        $comments->lazyInverseRelationshipName = "order";
        $comments->isToMany = true;
        $comments->isOptional = true;

        $order = new EntityDescription();
        $order->name = "NSOrder";
        $order->managedObjectClassName = NSOrder::class;
        $order->properties = new ArrayClass([$folio, $comments]);

        $content = new AttributeDescription();
        $content->name = "content";
        $content->type = AttributeType::string;

        $orderRel = new RelationshipDescription();
        $orderRel->name = "order";
        $orderRel->lazyDestinationEntityName = "NSOrder";
        $orderRel->lazyInverseRelationshipName = "comments";
        $orderRel->isOptional = true;

        $userRel = new RelationshipDescription();
        $userRel->name = "user";
        $userRel->lazyDestinationEntityName = "NSUser";
        $userRel->lazyInverseRelationshipName = "comments";
        $userRel->isOptional = true;

        $comment = new EntityDescription();
        $comment->name = "NSComment";
        $comment->managedObjectClassName = NSComment::class;
        $comment->properties = new ArrayClass([$content, $orderRel, $userRel]);

        $username = new AttributeDescription();
        $username->name = "username";
        $username->type = AttributeType::string;

        // The inverse closes a cycle (comment -> user -> comments), which is what the walk must survive.
        $userComments = new RelationshipDescription();
        $userComments->name = "comments";
        $userComments->lazyDestinationEntityName = "NSComment";
        $userComments->lazyInverseRelationshipName = "user";
        $userComments->isToMany = true;
        $userComments->isOptional = true;

        $user = new EntityDescription();
        $user->name = "NSUser";
        $user->managedObjectClassName = NSUser::class;
        $user->properties = new ArrayClass([$username, $userComments]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$order, $comment, $user]);
        return $model;
    }

    private static function shape(): Dictionary
    {
        return Dictionary::dictionaryWithArray([
            "folio" => AttributeType::string,
            "comments" => Dictionary::dictionaryWithArray([
                "content" => AttributeType::string,
                "user" => Dictionary::dictionaryWithArray([
                    "username" => AttributeType::string,
                ]),
            ]),
        ]);
    }

    private function seed(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::model());
        $user = new NSUser($context);
        $user->username = "claudiolazcano";

        $order = new NSOrder($context);
        $order->folio = "33805846";

        $comment = new NSComment($context);
        $comment->content = "Prueba";
        $comment->user = $user;
        $comment->order = $order;

        $context->save();
        return $context;
    }

    /** @return array<string, mixed> */
    private function fetchAndSerialize(ManagedObjectContext $context): array
    {
        $request = new FetchRequest("NSOrder");
        $request->serialization = self::shape();
        return json_decode(json_encode($context->fetch($request)->first->jsonSerialize()), true);
    }

    public function testANestedRelationshipInsideAToManyIsSerialized(): void
    {
        $context = $this->seed();
        $body = null;
        $context->performBlockAndWait(function () use ($context, &$body): void {
            $body = $this->fetchAndSerialize($context);
        });

        $this->assertSame("claudiolazcano", $body["comments"][0]["user"]["username"]);
    }

    /**
     * The update path: fetch with the shape, append a child, save, then fetch again to build the
     * response. The appended child must carry the nested relationship its siblings carry.
     */
    public function testAChildAddedDuringAnUpdateStillCarriesItsNestedRelationship(): void
    {
        $context = $this->seed();
        $body = null;
        $context->performBlockAndWait(function () use ($context, &$body): void {
            $request = new FetchRequest("NSOrder");
            $request->serialization = self::shape();
            $order = $context->fetch($request)->first;
            $order->folio = "99999999";

            $comment = new NSComment($context);
            $comment->content = "Nuevo";
            $comment->user = $context->fetch(new FetchRequest("NSUser"))->first;
            $comment->order = $order;
            $context->save();

            $body = $this->fetchAndSerialize($context);
        });

        $this->assertCount(2, $body["comments"]);
        foreach ($body["comments"] as $comment) {
            $this->assertArrayHasKey("user", $comment, "every comment must carry the nested user the shape asked for");
            $this->assertSame("claudiolazcano", $comment["user"]["username"]);
        }
    }
}
