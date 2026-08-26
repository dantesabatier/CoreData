<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 15/06/20
 * Time: 06:50
 */

namespace Sabatier\CoreData;

use Override;
use Sabatier\Foundation\CompareOptions;
use Sabatier\Foundation\ObjectClass;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\string_is_equal;

/**
 * A compact, universal identifier for a managed object.
 *
 * This identifier forms the basis for uniquing in the Core Data Framework. A managed object ID uniquely identifies the same managed object both between managed object contexts in a single application and in multiple applications (as in distributed systems). Identifiers contain the information needed to exactly describe an object in a persistent store (like the primary key in the database), although the detailed information is not exposed. The framework completely encapsulates the “external” information and presents a clean object-oriented interface.
 * Object IDs can be transformed into a URI representation which can be archived and recreated later to refer back to a given object (using {@see PersistentStoreCoordinator::managedObjectID()}) (PersistentStoreCoordinator) and {@see ManagedObjectContext::object()} (ManagedObjectContext). For example, the last selected group in an application could be stored in the user defaults through the group object's ID. You can also use object ID URI representations to store “weak” relationships across persistent stores (where no hard join is possible).
 * @psalm-suppress MissingConstructor
 */
final class ManagedObjectID extends ObjectClass implements FetchRequestResult
{
    /** @var PersistentStore|null The persistent store that fetched the object for the object ID. */
    public ?PersistentStore $persistentStore = null;
    /** @var bool A Boolean value that indicates whether the object ID is temporary. Most object IDs return false. New objects inserted into a managed object context are assigned a temporary ID which is replaced with a permanent one once the object gets saved to a persistent store. */
    public bool $isTemporaryID {
        get => $this->persistentStore === null || !is_numeric($this->referenceObject);
    }
    /** @var EntityDescription The entity description associated with the object ID. */
    public EntityDescription $entity {
        get => $this->entity ??= $this->persistentStore?->persistentStoreCoordinator?->managedObjectModel?->entitiesByName?->valueForKey($this->entityName) ?? fatal_error("Unable to resolve entity \"$this->entityName\" for object ID");
    }
    /** @internal */
    public string|int $referenceObject {
        get => $this->referenceObject ??= new UUID()->uuidString;
    }
    /** @internal */
    private(set) string $entityName {
        get => $this->entityName ??= $this->entity->name;
    }
    private bool $isStoreIdentifierResolved = false;
    /** @internal */
    private(set) ?string $storeIdentifier {
        get {
            if ($this->isStoreIdentifierResolved) {
                return $this->storeIdentifier;
            }
            $this->isStoreIdentifierResolved = true;
            return $this->storeIdentifier = $this->persistentStore?->identifier;
        }
    }
    #[Override]
    public string $description {
        get => sprintf("<%s>", $this->uriRepresentation()->absoluteString);
    }
    #[Override]
    public string $debugDescription {
        get => sprintf("<%s: %s> %s", $this->class, $this->hash, $this->entityName);
    }
    #[Override]
    public string $canonicalDescription {
        get => sprintf("<%s: %s> %s", $this->storeIdentifier, $this->entity->name, $this->referenceObject);
    }

    /**
     * @param EntityDescription $entity The entity description associated with the object ID.
     * @param int|string $referenceObject
     */
    public function __construct(EntityDescription $entity, /** @internal */ int|string $referenceObject)
    {
        $this->entity = $entity;
        $this->referenceObject = $referenceObject;
    }

    public function __serialize(): array
    {
        return ["entityName" => $this->entityName, "referenceObject" => $this->referenceObject, "storeIdentifier" => $this->storeIdentifier];
    }

    public function __unserialize(array $data): void
    {
        $this->entityName = $data["entityName"];
        $this->referenceObject = $data["referenceObject"];
        $this->storeIdentifier = $data["storeIdentifier"];
        $this->isStoreIdentifierResolved = true;
    }

    /**
     * Returns a URI that provides an archiveable reference to the object for the object ID.
     *
     * If the corresponding managed object has not yet been saved, the object ID (and hence URI) is a temporary value that will change when the corresponding managed object is saved.
     * @return URL A URL object containing a URI that provides an archiveable reference to the object which the receiver represents.
     */
    public function uriRepresentation(): URL
    {
        $url = new URL("x-coredata://$this->storeIdentifier");
        $url->appendPathComponent($this->entityName);
        if ($referenceObject = $this->referenceObject) {
            $url->appendPathComponent((string)$referenceObject);
        }
        return $url;
    }

    #[Override]
    public function isEqual(mixed $other): bool
    {
        if ($other instanceof ManagedObjectID) {
            // Equivalent to comparing uriRepresentation() of both ("x-coredata://{store}/{entity}/{ref}"), but without building two URL objects per comparison. The URI compare is case-insensitive (URL::compare uses CompareOptions::caseInsensitive) and stringifies referenceObject, so we replicate both here. This is a hot path during deep-graph hydration (profiled: uriRepresentation dominated by isEqual's two URL builds).
            return string_is_equal((string)$this->storeIdentifier, (string)$other->storeIdentifier, CompareOptions::caseInsensitive)
                && string_is_equal($this->entityName, $other->entityName, CompareOptions::caseInsensitive)
                && string_is_equal((string)$this->referenceObject, (string)$other->referenceObject, CompareOptions::caseInsensitive);
        }
        return false;
    }

    #[Override]
    public function jsonSerialize(): int|string
    {
        return $this->referenceObject;
    }
}
