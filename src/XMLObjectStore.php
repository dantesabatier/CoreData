<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:09
 */

namespace Sabatier\CoreData;

use BackedEnum;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use Exception;
use Override;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;
use Sabatier\Foundation\Value;
use function Sabatier\Foundation\fatal_error;

/** @internal */
final class XMLObjectStore extends AtomicStore
{
    #[Override]
    public string $type {
        get => XMLStoreType;
    }
    private DOMDocument $document {
        /**
         * @throws Exception
         */
        get => $this->document ??= $this->createDocument();
    }
    /** @var Dictionary<EntityDescription> */
    private Dictionary $entitiesForConfiguration {
        get => $this->entitiesForConfiguration ??= ($this->persistentStoreCoordinator->managedObjectModel->entities($this->configurationName) ?? $this->persistentStoreCoordinator->managedObjectModel->entities)->reduce(new Dictionary(),
            /**
             * @param Dictionary<EntityDescription> $result
             * @param EntityDescription $entityDescription
             * @return Dictionary<EntityDescription>
             */
            function (Dictionary $result, EntityDescription $entityDescription): Dictionary {
                $result[$entityDescription->name] = $entityDescription;
                return $result;
            });
    }
    private Dictionary $xmlInfo {
        get => $this->xmlInfo ??= new Dictionary();
    }

    private static function loadMetadataFromDocument(DOMDocument $document): Dictionary
    {
        $model = $document->getElementsByTagName("model")->item(0);
        assert($model instanceof DOMElement);
        $metadataXML = $model->getElementsByTagName("metadata")->item(0);
        assert($metadataXML instanceof DOMElement);
        /** @var Dictionary<string> $metadata */
        $metadata = new Dictionary();
        foreach ($metadataXML->childNodes as $element) {
            if ($key = $element->localName) {
                $metadata[$key] = $element->nodeValue;
            }
        }
        return $metadata;
    }

    #[Override]
    public static function metadataForPersistentStore(URL $url): Dictionary
    {
        $document = new DOMDocument("1.0", "UTF-8");
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        return self::loadMetadataFromDocument($document);
    }

    #[Override]
    public static function setMetadata(?Dictionary $metadata, URL $url): bool
    {
        $path = $url->path;
        $metadata ??= new Dictionary([StoreTypeKey => XMLStoreType, StoreUUIDKey => new UUID()->uuidString]);
        $document = new DOMDocument("1.0", "UTF-8");
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        if (FileManager::default()->fileExists($path)) {
            $document->load($path);
            $model = $document->getElementsByTagName("model")->item(0);
        } else {
            $model = $document->createElement("model");
        }
        if ($model instanceof DOMElement) {
            $metadataXML = $model->getElementsByTagName("metadata")->item(0);
            if ($metadataXML instanceof DOMElement) {
                foreach ($metadata as $key => $value) {
                    if (!($element = new ArrayClass($metadataXML->getElementsByTagName($key))->first())) {
                        $element = $document->createElement($key, $value);
                        $metadataXML->appendChild($element);
                        continue;
                    }
                    $element->nodeValue = $value;
                }
                return (bool)$document->save($path);
            }
        }
        return false;
    }

    private function loadFromDocument(DOMDocument $document): void
    {
        /** @var Set<AtomicStoreCacheNode> $cacheNodes */
        $cacheNodes = new Set();
        $model = $document->getElementsByTagName("model")->item(0);
        assert($model instanceof DOMElement);
        $parent = $model->getElementsByTagName("elements")->item(0);
        assert($parent instanceof DOMElement);
        $children = $parent->getElementsByTagName("element");
        /** @var DOMElement $element */
        foreach ($children as $element) {
            $entityName = $element->getAttribute("name");
            $entity = $this->entitiesForConfiguration[$entityName];
            assert($entity instanceof EntityDescription);
            /** @var Dictionary<mixed> $info */
            $info = $this->xmlInfo[$entity->name] ?? new Dictionary();
            $cacheNode = $this->createCacheNodeFromXMLElement($element);
            $attributeElements = $element->getElementsByTagName("attribute");
            /** @var DOMElement $attributeElement */
            foreach ($attributeElements as $attributeElement) {
                $key = $attributeElement->getAttribute("name");
                if (!($attribute = $entity->attributesByName[$key])) {
                    fatal_error("Entity \"$entity->name\" does not contains an attribute named \"$key\"");
                }
                $info[$attribute->name] = $attributeElement->attributes;
                $value = $attributeElement->nodeValue;
                ManagedObject::coerceValue($value, $attribute);
                if ($value === null) {
                    continue;
                }
                $cacheNode->setValueForKey($value, $key);
            }
            $relationshipElements = $element->getElementsByTagName("relationship");
            /** @var DOMElement $relationshipElement */
            foreach ($relationshipElements as $relationshipElement) {
                $key = $relationshipElement->getAttribute("name");
                if (!($relationship = $entity->relationshipsByName[$key])) {
                    fatal_error("Entity \"$entity->name\" does not contains a relationship named \"$key\"");
                }
                $info[$relationship->name] = $relationshipElement->attributes;
                if (($references = $relationshipElement->getAttribute("references")) && ($destination = $relationshipElement->getAttribute("destination")) && ($destinationEntity = $this->entitiesForConfiguration[$destination])) {
                    $managedObjectIDs = new Set(explode(" ", $references))->map(fn(string $reference): ManagedObjectID => $this->objectID($destinationEntity, (int)$reference));
                    $value = $relationship->isToMany ? $managedObjectIDs : $managedObjectIDs->first;
                    $cacheNode->setValueForKey($value, $key);
                }
            }
            $this->xmlInfo[$entity->name] = $info;
            $cacheNodes->insert($cacheNode);
        }
        $this->addCacheNodes($cacheNodes);
    }

    private function createCacheNodeFromXMLElement(DOMElement $element): XMLObjectStoreCacheNode
    {
        $entityName = $element->getAttribute("name");
        /** @var EntityDescription $entity */
        $entity = $this->entitiesForConfiguration[$entityName];
        $referenceObject = $element->getAttribute("id");
        $objectID = $this->objectID($entity, (int)$referenceObject);
        return new XMLObjectStoreCacheNode($element, $objectID);
    }

    /**
     * @throws Exception
     */
    #[Override]
    public function newCacheNode(ManagedObject $object): AtomicStoreCacheNode
    {
        $document = $this->document;
        $objectID = $object->objectID;
        $parent = $document->getElementsByTagName("elements")->item(0);
        $element = $document->createElement("element");
        $element->setAttribute("id", (string)$objectID->referenceObject);
        $element->setAttribute("name", $objectID->entityName);
        $parent->appendChild($element);
        $node = new XMLObjectStoreCacheNode($element, $objectID);
        $this->updateCacheNode($node, $object);
        return $node;
    }

    /**
     * @throws Exception
     */
    private function updateXMLNode(DOMNode $node, ManagedObject $object): void
    {
        foreach ($object->entity->attributesByName as $attribute) {
            if ($attribute->isTransient) {
                continue;
            }
            if ($attribute instanceof DerivedAttributeDescription) {
                continue;
            }
            if ($attribute instanceof CompositeAttributeDescription) {
                continue;
            }
            $value = $this->getXMLAttributeValueFromObject($object, $attribute);
            if ($value !== null) {
                $this->createAttributeChildOnNode($node, $attribute, $value);
            }
        }
        foreach ($object->entity->relationshipsByName as $key => $relationship) {
            if (!$this->shouldWriteRelationship($object, $key)) {
                continue;
            }
            $value = $this->relationshipValueToWrite($object, $key);
            $relationshipNode = $this->createRelationshipChildOnNode($node, $relationship);
            $destinationNode = $relationshipNode->getAttributeNode("destination");
            $referencesNode = $relationshipNode->getAttributeNode("references");
            /** @psalm-suppress UndefinedPropertyAssignment */
            $referencesNode->value = $this->getIDRefString($value, $relationship);
            $inverseRelationship = $relationship->inverseRelationship;
            /** @var Set<ManagedObjectID> $managedObjectIDs */
            $managedObjectIDs = $this->managedObjectIDs($value, $relationship);
            foreach ($managedObjectIDs as $managedObjectID) {
                /** @psalm-suppress UndefinedPropertyAssignment */
                $destinationNode->value = $managedObjectID->entityName;
                $cacheNode = $this->cacheNode($managedObjectID);
                if ($cacheNode instanceof XMLObjectStoreCacheNode) {
                    $relationshipNode = $this->createRelationshipChildOnNode($cacheNode->data, $inverseRelationship);
                    $referencesNode = $relationshipNode->getAttributeNode("references");
                    $references = new Set(explode(" ", $relationshipNode->getAttribute("references")));
                    $references->formUnion(new Set(explode(" ", $this->getIDRefString($object, $inverseRelationship))));
                    /** @psalm-suppress UndefinedPropertyAssignment */
                    $referencesNode->value = trim($references->join(" "));
                    $destinationNode = $relationshipNode->getAttributeNode("destination");
                    /** @psalm-suppress UndefinedPropertyAssignment */
                    $destinationNode->value = $object->entity->name;
                }
            }
        }
        $node->normalize();
    }

    private function shouldWriteRelationship(ManagedObject $object, string $key): bool
    {
        $value = $object->primitiveValueForKey($key);
        // A refaulted to-many is empty, but a newly built FaultingSet can still carry explicit
        // members before its fault flag is cleared. Those members are real data and must be saved.
        return !$object->isPropertyForKeyFault($key)
            || ($value instanceof Set && !$value->isEmpty)
            || $object->changedValuesForCurrentEvent()->offsetExists($key);
    }

    private function relationshipValueToWrite(ManagedObject $object, string $key): Set|ManagedObject|ManagedObjectID|Nil|null
    {
        $changedValues = $object->changedValuesForCurrentEvent();
        if ($changedValues->offsetExists($key)) {
            $value = $changedValues[$key];
            return $value instanceof Nil ? null : $value;
        }
        return $object->primitiveValueForKey($key);
    }

    private function removeInverseXMLReference(ManagedObjectID $destinationID, RelationshipDescription $inverseRelationship, ManagedObjectID $sourceID): void
    {
        $cacheNode = $this->cacheNode($destinationID);
        if (!$cacheNode instanceof XMLObjectStoreCacheNode) {
            return;
        }
        $relationshipNode = $this->createRelationshipChildOnNode($cacheNode->data, $inverseRelationship);
        $references = new Set(explode(" ", $relationshipNode->getAttribute("references")));
        $references->remove((string)$sourceID->referenceObject);
        $referencesNode = $relationshipNode->getAttributeNode("references");
        /** @psalm-suppress UndefinedPropertyAssignment */
        $referencesNode->value = trim($references->join(" "));
    }

    private function getIDRefString(Set|ManagedObject|ManagedObjectID|Nil|null $value, ?RelationshipDescription $relationship = null): string
    {
        return $this->managedObjectIDs($value, $relationship)->map(fn(ManagedObjectID $objectID): string|int => $objectID->referenceObject)->join(" ");
    }

    private function managedObjectIDs(/** @noinspection PhpUnusedParameterInspection */ Set|ManagedObject|ManagedObjectID|Nil|null $value, ?RelationshipDescription $relationship = null): Set
    {
        /** @var Set<ManagedObjectID> $managedObjectIDs */
        $managedObjectIDs = new Set();
        if ($value instanceof Set) {
            $managedObjectIDs->formUnion($value->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID));
        } elseif ($value instanceof ManagedObject) {
            $managedObjectIDs->insert($value->objectID);
        } elseif ($value instanceof ManagedObjectID) {
            $managedObjectIDs->insert($value);
        }
        return $managedObjectIDs;
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    private function createRelationshipChildOnNode(DOMNode $node, RelationshipDescription $relationship): DOMElement
    {
        assert($node instanceof DOMElement);
        if (!($element = new ArrayClass($node->getElementsByTagName("relationship"))->first(fn(DOMElement $element): bool => $element->getAttribute("name") === $relationship->name))) {
            $destinationEntity = $relationship->destinationEntity;
            $document = $this->document;
            $element = $document->createElement("relationship");
            assert($element instanceof DOMElement);
            $element->setAttribute("name", $relationship->name);
            $element->setAttribute("type", "$relationship->minCount/$relationship->maxCount");
            $element->setAttribute("destination", $destinationEntity->name);
            $element->setAttribute("references", "");
            $node->appendChild($element);
        }
        return $element;
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    private function createAttributeChildOnNode(DOMNode $node, AttributeDescription $attribute, ?string $value = null): void
    {
        assert($node instanceof DOMElement);
        if (!($element = new ArrayClass($node->getElementsByTagName("attribute"))->first(fn(DOMElement $element): bool => $element->getAttribute("name") === $attribute->name))) {
            $element = $this->document->createElement("attribute", $value ?? "");
            assert($element instanceof DOMElement);
            $element->setAttribute("name", $attribute->name);
            $element->setAttribute("type", $attribute->type->name);
            $node->appendChild($element);
        }
        $element->nodeValue = $value;
    }

    /**
     * @throws Exception
     */
    private function getXMLAttributeValueFromObject(ManagedObject $object, AttributeDescription $attribute): ?string
    {
        $value = $object->primitiveValueForKey($attribute->name);
        switch ($attribute->type) {
            case AttributeType::integer16:
            case AttributeType::integer32:
            case AttributeType::integer64:
            case AttributeType::boolean:
            case AttributeType::decimal:
            case AttributeType::double:
            case AttributeType::float:
                if ($value instanceof BackedEnum || $value instanceof Value) {
                    $value = $value->value;
                }
                $value = json_encode($value, JSON_THROW_ON_ERROR);
                break;
            case AttributeType::uri:
            case AttributeType::uuid:
            case AttributeType::binaryData:
            case AttributeType::date:
            case AttributeType::string:
            case AttributeType::objectID:
            case AttributeType::transformable:
                ManagedObject::coerceValue($value, $attribute);
                if (empty($value)) {
                    $value = null;
                }
                break;
            default:
                break;
        }
        return $value;
    }

    /**
     * @throws Exception
     */
    private function createDocument(): DOMDocument
    {
        $document = new DOMDocument("1.0", "UTF-8");
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;
        $path = $this->url->path;
        if (FileManager::default()->fileExists($path)) {
            $options = 0;
            if ($this->options?->valueForKey(ValidateXMLStoreOption)) {
                $options |= LIBXML_DTDLOAD;
            }
            $document->load($path, $options);
            return $document;
        }
        $type = $document->createElement(StoreTypeKey, $this->type);
        $uuid = $document->createElement(StoreUUIDKey, $this->identifier);
        $metadata = $document->createElement("metadata");
        $metadata->appendChild($uuid);
        $metadata->appendChild($type);
        $model = $document->createElement("model");
        $model->appendChild($metadata);
        $parent = $document->createElement("elements");
        $model->appendChild($parent);
        $document->appendChild($model);
        return $document;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function retainedXmlInfoForRelationship(RelationshipDescription $relationship): mixed
    {
        return $this->xmlInfo->valueForKey($relationship->entity->name)?->valueForKey($relationship->name);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function xmlInfoForAttribute(AttributeDescription $attribute): mixed
    {
        return $this->xmlInfo->valueForKey($attribute->entity->name)?->valueForKey($attribute->name);
    }

    /**
     * @throws Exception
     */
    #[Override]
    public function updateCacheNode(AtomicStoreCacheNode $node, ManagedObject $object): void
    {
        $entity = $object->entity;
        foreach ($entity->attributesByName as $key => $attribute) {
            if ($attribute->isTransient) {
                continue;
            }
            if ($attribute instanceof DerivedAttributeDescription) {
                continue;
            }
            if ($attribute instanceof CompositeAttributeDescription) {
                continue;
            }
            $node->setValueForKey($object->primitiveValueForKey($key), $key);
        }
        foreach ($entity->relationshipsByName as $key => $relationship) {
            if (!$this->shouldWriteRelationship($object, $key)) {
                continue;
            }
            $value = $this->relationshipValueToWrite($object, $key);
            $managedObjectIDs = $this->managedObjectIDs($value, $relationship);
            if (!$relationship->isToMany) {
                $storedObjectID = $node->valueForKey($key);
                if ($storedObjectID instanceof ManagedObject) {
                    $storedObjectID = $storedObjectID->objectID;
                }
                if ($storedObjectID instanceof ManagedObjectID && !$managedObjectIDs->containsElement($storedObjectID)) {
                    $this->removeInverseXMLReference($storedObjectID, $relationship->inverseRelationship, $object->objectID);
                }
            }
            $value = $relationship->isToMany ? $managedObjectIDs : $managedObjectIDs->first;
            $node->setValueForKey($value, $key);
        }
        if ($node instanceof XMLObjectStoreCacheNode) {
            $this->updateXMLNode($node->data, $object);
        }
    }

    /**
     * Detaches the XML elements backing the given cache nodes and repairs the surrounding document:
     * cascading to referenced children, and scrubbing dangling references to the deleted
     * nodes from every other element.
     *
     * The cascade branch below is NOT redundant with the context's own delete-rule handling, even
     * though it looks it: the context only cascades to children it has materialized, so a child
     * that exists in the file but was never faulted into the context would otherwise be orphaned.
     * This walks the stored "references" directly, so it prunes such children regardless. When the
     * context DID cascade (the common case), the child is also in $cacheNodes and may already be
     * detached — hence the parent-node guards before each removeChild.
     *
     * @throws Exception
     */
    #[Override]
    public function willRemoveCacheNodes(Set $cacheNodes): void
    {
        $document = $this->document;
        $model = $document->getElementsByTagName("model")->item(0);
        assert($model instanceof DOMElement);
        $parent = $model->getElementsByTagName("elements")->item(0);
        assert($parent instanceof DOMElement);
        /** @var ArrayClass<DOMElement> $children */
        $children = new ArrayClass($parent->getElementsByTagName("element"));
        /** @var AtomicStoreCacheNode $cacheNode */
        foreach ($cacheNodes as $cacheNode) {
            if ($cacheNode instanceof XMLObjectStoreCacheNode) {
                $entity = $cacheNode->objectID->entity;
                /** @var ArrayClass<DOMElement> $deletedElements */
                $deletedElements = $children->filter(fn(DOMElement $element): bool => $element->isSameNode($cacheNode->data));
                /** @var DOMElement $deletedElement */
                foreach ($deletedElements as $deletedElement) {
                    $relationshipElements = $deletedElement->getElementsByTagName("relationship");
                    foreach ($relationshipElements as $relationshipElement) {
                        /** @var string $name */
                        $name = $relationshipElement->getAttribute("name");
                        $relationship = $entity->relationshipsByName[$name] ?? fatal_error("Entity \"$entity->name\" does not contains a relationship named \"$name\"");
                        /** @psalm-suppress PossiblyNullPropertyFetch */
                        if ($relationship->deleteRule === DeleteRule::cascadeDeleteRule) {
                            /** @var string $destination */
                            $destination = $relationshipElement->getAttribute("destination");
                            $references = new Set(explode(" ", $relationshipElement->getAttribute("references")));
                            foreach ($references as $reference) {
                                $remainingElements = $children->filter(fn(DOMElement $element): bool => $element->getAttribute("name") === $destination && $element->getAttribute("id") === $reference);
                                foreach ($remainingElements as $remainingElement) {
                                    // The cascaded node may also be in $cacheNodes (the context cascades deletions too); only detach it while it is still attached.
                                    if ($remainingElement->parentNode?->isSameNode($parent)) {
                                        $parent->removeChild($remainingElement);
                                    }
                                }
                            }
                        }
                    }
                    $reference = (string)$cacheNode->objectID->referenceObject;
                    foreach ($children as $element) {
                        $relationshipElements = $element->getElementsByTagName("relationship");
                        foreach ($relationshipElements as $relationshipElement) {
                            if ($entity->name === $relationshipElement->getAttribute("destination")) {
                                $references = new Set(explode(" ", $relationshipElement->getAttribute("references")));
                                if ($references->containsElement($reference)) {
                                    $references->remove($reference);
                                    /** @var DOMAttr $referencesNode */
                                    $referencesNode = $relationshipElement->getAttributeNode("references");
                                    $referencesNode->value = $references->join(" ");
                                }
                            }
                        }
                    }
                    // A cascade rule processed earlier in this loop may have detached this element already.
                    if ($deletedElement->parentNode?->isSameNode($parent)) {
                        $parent->removeChild($deletedElement);
                    }
                }
            }
        }
    }

    #[Override]
    public function willRemove(PersistentStoreCoordinator $coordinator): void
    {
    }

    #[Override]
    public function load(): bool
    {
        $document = $this->document;
        $this->metadata = self::loadMetadataFromDocument($document);
        $this->identifier = $this->metadata[StoreUUIDKey];
        $this->loadFromDocument($document);
        return true;
    }

    #[Override]
    public function save(): bool
    {
        return (bool)$this->document->save($this->url->path);
    }
}
