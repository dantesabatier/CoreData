<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 15/06/20
 * Time: 06:50
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\URL;

/**
 * A compact, universal identifier for a managed object.
 *
 * This identifier forms the basis for uniquing in the Core Data Framework. A managed object ID uniquely identifies the same managed object both between managed object contexts in a single application, and in multiple applications (as in distributed systems). Identifiers contain the information needed to exactly describe an object in a persistent store (like the primary key in the database), although the detailed information is not exposed. The framework completely encapsulates the “external” information and presents a clean object-oriented interface.
 * Object IDs can be transformed into a URI representation which can be archived and recreated later to refer back to a given object (using {@see PersistentStoreCoordinator::managedObjectID()}) (PersistentStoreCoordinator) and {@see ManagedObjectContext::object()} (ManagedObjectContext). For example, the last selected group in an application could be stored in the user defaults through the group object's ID. You can also use object ID URI representations to store “weak” relationships across persistent stores (where no hard join is possible).
 * @psalm-suppress MissingConstructor
 */
class ManagedObjectID extends ObjectClass implements FetchRequestResult
{
    /** @var PersistentStore|null The persistent store that fetched the object for the object ID. */
    public ?PersistentStore $persistentStore = null;
    /** @var bool A Boolean value that indicates whether the object ID is temporary. Most object IDs return false. New objects inserted into a managed object context are assigned a temporary ID which is replaced with a permanent one once the object gets saved to a persistent store. */
    public bool $isTemporaryID {
        get => $this->persistentStore === null || !is_int($this->referenceObject);
    }
    /** @internal */
    public readonly string $entityName;
    /** @internal */
    public readonly ?string $storeIdentifier;
    public string $description {
        get => sprintf("<%s>", $this->uriRepresentation()->absoluteString);
    }
    public string $debugDescription {
        get => sprintf("<%s: %s> %s", self::class, $this->hash, $this->entityName);
    }

    /**
     * @param EntityDescription $entity The entity description associated with the object ID.
     * @param int|string $referenceObject
     */
    public function __construct(public EntityDescription $entity, /** @internal */ public int|string $referenceObject)
    {
        $this->entityName = $this->entity->name;
    }

    public function __serialize(): array
    {
        $serialization = ["entityName" => $this->entity->name, "referenceObject" => $this->referenceObject];
        if ($persistentStore = $this->persistentStore) {
            $serialization["storeIdentifier"] = $persistentStore->identifier;
        }
        return $serialization;
    }

    public function __unserialize(array $data): void
    {
        $this->entityName = $data["entityName"];
        $this->referenceObject = $data["referenceObject"];
        $this->storeIdentifier = $data["storeIdentifier"] ?? null;
    }

    /**
     * Returns a URI that provides an archiveable reference to the object for the object ID.
     *
     * If the corresponding managed object has not yet been saved, the object ID (and hence URI) is a temporary value that will change when the corresponding managed object is saved.
     * @return URL A URL object containing a URI that provides an archiveable reference to the object which the receiver represents.
     */
    public function uriRepresentation(): URL
    {
        $url = new URL("x-coredata://{$this->persistentStore?->identifier}");
        $url->appendPathComponent($this->entity->name);
        if ($referenceObject = $this->referenceObject) {
            $url->appendPathComponent((string)$referenceObject);
        }
        return $url;
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof ManagedObjectID) {
            return $this->uriRepresentation()->isEqual($other->uriRepresentation());
        }
        return false;
    }

    #[Override]
    public function jsonSerialize(): int|string
    {
        return $this->referenceObject;
    }
}
