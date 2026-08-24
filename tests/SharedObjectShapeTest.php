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

final class SOPost extends ManagedObject
{
}

final class SOComment extends ManagedObject
{
}

final class SOUser extends ManagedObject
{
}

/**
 * The same object reached through two branches that ask for different shapes: a post's author, and the
 * author of one of its comments — one SOUser, asked for by email down one branch and by name down the other.
 *
 * An object carries a single set of serialization keys, so it cannot answer with two different shapes. The
 * walk therefore grows the shape on a revisit instead of letting the last branch overwrite the first: the
 * shared object emits the union, and neither branch silently loses the field it asked for.
 */
final class SharedObjectShapeTest extends SQLMigrationTestCase
{
    private static function model(): ManagedObjectModel
    {
        $title = new AttributeDescription();
        $title->name = "title";
        $title->type = AttributeType::string;

        $author = new RelationshipDescription();
        $author->name = "author";
        $author->lazyDestinationEntityName = "SOUser";
        $author->lazyInverseRelationshipName = "posts";
        $author->isOptional = true;

        $comments = new RelationshipDescription();
        $comments->name = "comments";
        $comments->lazyDestinationEntityName = "SOComment";
        $comments->lazyInverseRelationshipName = "post";
        $comments->isToMany = true;
        $comments->isOptional = true;

        $post = new EntityDescription();
        $post->name = "SOPost";
        $post->managedObjectClassName = SOPost::class;
        $post->properties = new ArrayClass([$title, $author, $comments]);

        $content = new AttributeDescription();
        $content->name = "content";
        $content->type = AttributeType::string;

        $postRel = new RelationshipDescription();
        $postRel->name = "post";
        $postRel->lazyDestinationEntityName = "SOPost";
        $postRel->lazyInverseRelationshipName = "comments";
        $postRel->isOptional = true;

        $commentUser = new RelationshipDescription();
        $commentUser->name = "user";
        $commentUser->lazyDestinationEntityName = "SOUser";
        $commentUser->lazyInverseRelationshipName = "comments";
        $commentUser->isOptional = true;

        $comment = new EntityDescription();
        $comment->name = "SOComment";
        $comment->managedObjectClassName = SOComment::class;
        $comment->properties = new ArrayClass([$content, $postRel, $commentUser]);

        $username = new AttributeDescription();
        $username->name = "username";
        $username->type = AttributeType::string;

        $email = new AttributeDescription();
        $email->name = "email";
        $email->type = AttributeType::string;

        $nickname = new AttributeDescription();
        $nickname->name = "nickname";
        $nickname->type = AttributeType::string;

        $posts = new RelationshipDescription();
        $posts->name = "posts";
        $posts->lazyDestinationEntityName = "SOPost";
        $posts->lazyInverseRelationshipName = "author";
        $posts->isToMany = true;
        $posts->isOptional = true;

        $userComments = new RelationshipDescription();
        $userComments->name = "comments";
        $userComments->lazyDestinationEntityName = "SOComment";
        $userComments->lazyInverseRelationshipName = "user";
        $userComments->isToMany = true;
        $userComments->isOptional = true;

        $user = new EntityDescription();
        $user->name = "SOUser";
        $user->managedObjectClassName = SOUser::class;
        $user->properties = new ArrayClass([$username, $email, $nickname, $posts, $userComments]);

        $model = new ManagedObjectModel();
        $model->entities = new ArrayClass([$post, $comment, $user]);
        return $model;
    }

    /**
     * The author of the post is also the author of its comment: one SOUser object, reached through
     * two branches whose shapes disagree.
     */
    private function seed(): ManagedObjectContext
    {
        $context = $this->bootstrap(self::model());
        $user = new SOUser($context);
        $user->username = "claudiolazcano";
        $user->email = "claudio@example.com";
        $user->nickname = "Lazca";

        $post = new SOPost($context);
        $post->title = "Resurtido";
        $post->author = $user;

        $comment = new SOComment($context);
        $comment->content = "Prueba";
        $comment->user = $user;
        $comment->post = $post;

        $context->save();
        return $context;
    }

    public function testTwoBranchesAskingDifferentShapesOfTheSameObject(): void
    {
        $context = $this->seed();
        $shape = Dictionary::dictionaryWithArray([
            "title" => AttributeType::string,
            "author" => Dictionary::dictionaryWithArray([
                "email" => AttributeType::string,
            ]),
            "comments" => Dictionary::dictionaryWithArray([
                "content" => AttributeType::string,
                "user" => Dictionary::dictionaryWithArray([
                    "username" => AttributeType::string,
                ]),
            ]),
        ]);

        $body = null;
        $context->performBlockAndWait(function () use ($context, $shape, &$body): void {
            $request = new FetchRequest("SOPost");
            $request->serialization = $shape;
            $body = json_decode(json_encode($context->fetch($request)->first->jsonSerialize()), true);
        });
        // One object carries one shape, so the shared user emits the union of what both branches asked for.
        $this->assertArrayHasKey("email", $body["author"], "the author branch asked for email");
        $this->assertArrayHasKey("username", $body["comments"][0]["user"], "the comments.user branch asked for username");
        $this->assertSame($body["author"], $body["comments"][0]["user"], "it is one object, so both branches show the same union");
        $this->assertArrayNotHasKey("nickname", $body["author"], "no branch asked for nickname");
    }

    /** The union is emitted because both branches' columns were loaded: the shape drives the fetch too. */
    public function testBothBranchesColumnsAreLoaded(): void
    {
        $context = $this->seed();
        $shape = Dictionary::dictionaryWithArray([
            "title" => AttributeType::string,
            "author" => Dictionary::dictionaryWithArray(["email" => AttributeType::string]),
            "comments" => Dictionary::dictionaryWithArray([
                "content" => AttributeType::string,
                "user" => Dictionary::dictionaryWithArray(["username" => AttributeType::string]),
            ]),
        ]);

        $values = null;
        $context->performBlockAndWait(function () use ($context, $shape, &$values): void {
            $request = new FetchRequest("SOPost");
            $request->serialization = $shape;
            $post = $context->fetch($request)->first;
            $user = $post->valueForKey("author");
            $values = ["email" => $user->valueForKey("email"), "username" => $user->valueForKey("username")];
        });

        $this->assertSame("claudio@example.com", $values["email"]);
        $this->assertSame("claudiolazcano", $values["username"], "the SQL layer loaded both branches columns");
    }
}
