<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use function Sabatier\Foundation\fatal_error;
use function Sabatier\Foundation\typeof;

/** @internal */
final readonly class RelationshipMapper
{
    public function __construct(private ManagedObjectResolver $objectResolver)
    {
    }

    /**
     * @param ManagedObject $contextObject
     * @param Dictionary<mixed> $mappedValues
     * @param string $key
     * @param mixed $value
     * @param RelationshipDescription $relationship
     */
    public function process(ManagedObject $contextObject, Dictionary $mappedValues, string $key, mixed $value, RelationshipDescription $relationship): void
    {
        $destinationEntity = $relationship->destinationEntity;
        if ($value instanceof ArrayClass || $value instanceof Set) {
            if ($relationship->isToMany) {
                $mappedValues[$key] = $value->compactMap(fn(ManagedObject|ManagedObjectID|Dictionary $object): ?ManagedObject => $this->objectResolver->resolve($destinationEntity, $object));
            } elseif (!$value->isEmpty) {
                $value
                    |> typeof(...)
                    |> (fn(string $x): string => sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $contextObject->entity->name, $x, $key))
                    |> (fn(string $x): never => fatal_error($x));
            }
        } elseif ($value instanceof ManagedObject || $value instanceof ManagedObjectID || $value instanceof Dictionary) {
            $mappedValues[$key] = $this->objectResolver->resolve($destinationEntity, $value);
        } elseif ($value instanceof Nil) {
            $mappedValues[$key] = $value;
        } else {
            $value
                |> typeof(...)
                |> (fn(string $x): string => sprintf("%s: Attempting to insert an unsupported value of type \"%s\" for relationship \"%s\"", $contextObject->entity->name, $x, $key))
                |> (fn(string $x): never => fatal_error($x));
        }
    }
}
