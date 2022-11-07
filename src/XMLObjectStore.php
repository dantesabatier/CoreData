<?php

/**
 * Created by PhpStorm.
 * User: dante
 * Date: 14/06/20
 * Time: 16:09
 */

namespace Sabatier\CoreData;

use DOMDocument;
use DOMElement;
use DOMNode;
use Exception;
use Sabatier\Foundation\ArrayClass;
use Sabatier\Foundation\Dictionary;
use Sabatier\Foundation\FileManager;
use Sabatier\Foundation\InternalInconsistencyException;
use Sabatier\Foundation\Nil;
use Sabatier\Foundation\Set;
use Sabatier\Foundation\UnknownKeyException;
use Sabatier\Foundation\URL;
use Sabatier\Foundation\UUID;

/** @internal */
class XMLObjectStore extends AtomicStore
{
    private ?DOMDocument $document = null;
    /** @var Dictionary<EntityDescription> */
    private readonly Dictionary $entitiesForConfiguration;
    /** @var Dictionary<mixed> */
    private Dictionary $xmlInfo;

    public function __construct(PersistentStoreCoordinator $coordinator, string $configurationName, URL $url, ?Dictionary $options = null)
    {
        parent::__construct($coordinator, $configurationName, $url, $options);
        /** @var Dictionary<EntityDescription> $entitiesForConfiguration */
        $entitiesForConfiguration = new Dictionary();
        $model = $coordinator->managedObjectModel;
        $entities = $model->entities($configurationName) ?? $model->entities;
        foreach ($entities as $entity) {
            $entitiesForConfiguration[$entity->name] = $entity;
        }
        $this->entitiesForConfiguration = $entitiesForConfiguration;
        $this->xmlInfo = new Dictionary();
    }

    private static function loadMetadataFromDocument(DOMDocument $document): Dictionary
    {
        $model = $document->getElementsByTagName('model')->item(0);
        assert($model instanceof DOMElement);
        $metadataXML = $model->getElementsByTagName('metadata')->item(0);
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

    public static function metadataForPersistentStore(URL $url): Dictionary
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        return self::loadMetadataFromDocument($document);
    }

    public static function setMetadata(?Dictionary $metadata, URL $url): bool
    {
        $path = $url->path;
        $metadata ??= new Dictionary([StoreTypeKey => XMLStoreType, StoreUUIDKey => (new UUID())->uuidString]);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        if (FileManager::default()->fileExists($path)) {
            $document->load($path);
            $model = $document->getElementsByTagName('model')->item(0);
        } else {
            $model = $document->createElement('model');
        }
        if ($model instanceof DOMElement) {
            $metadataXML = $model->getElementsByTagName('metadata')->item(0);
            if ($metadataXML instanceof DOMElement) {
                foreach ($metadata as $key => $value) {
                    if (!($element = (new ArrayClass($metadataXML->getElementsByTagName($key)))->first())) {
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
        $model = $document->getElementsByTagName('model')->item(0);
        assert($model instanceof DOMElement);
        $parent = $model->getElementsByTagName('elements')->item(0);
        assert($parent instanceof DOMElement);
        $children = $parent->getElementsByTagName('element');
        /** @var DOMElement $element */
        foreach ($children as $element) {
            $entityName = $element->getAttribute('name');
            $entity = $this->entitiesForConfiguration[$entityName];
            assert($entity instanceof EntityDescription);
            /** @var Dictionary<mixed> $info */
            $info = $this->xmlInfo[$entity->name] ?? new Dictionary();
            $cacheNode = $this->createCacheNodeFromXMLElement($element);
            $attributeElements = $element->getElementsByTagName('attribute');
            /** @var DOMElement $attributeElement */
            foreach ($attributeElements as $attributeElement) {
                $key = $attributeElement->getAttribute('name');
                if (!($attribute = $entity->attributesByName[$key])) {
                    throw new UnknownKeyException(sprintf("%s %s() entity \"%s\" does not contains an attribute named \"%s\"", self::class, __FUNCTION__, $entity->name, $key));
                }
                $info[$attribute->name] = $attributeElement->attributes;
                $value = ManagedObject::coercedValue($attributeElement->nodeValue, $attribute->type);
                if ($value === null) {
                    continue;
                }
                $cacheNode->setValueForKey($value, $key);
            }
            $relationshipElements = $element->getElementsByTagName('relationship');
            /** @var DOMElement $relationshipElement */
            foreach ($relationshipElements as $relationshipElement) {
                $key = $relationshipElement->getAttribute('name');
                if (!($relationship = $entity->relationshipsByName[$key])) {
                    throw new UnknownKeyException(sprintf("%s %s() entity \"%s\" does not contains a relationship named \"%s\"", self::class, __FUNCTION__, $entity->name, $key));
                }
                $info[$relationship->name] = $relationshipElement->attributes;
                if (($references = $relationshipElement->getAttribute('references')) && ($destination = $relationshipElement->getAttribute('destination')) && ($destinationEntity = $this->entitiesForConfiguration[$destination])) {
                    $managedObjectIDs = (new Set(explode(" ", $references)))->map(fn(string $reference): ManagedObjectID => $this->objectID($destinationEntity, (int)$reference));
                    $value = $relationship->isToMany ? $managedObjectIDs : $managedObjectIDs->first();
                    $cacheNode->setValueForKey($value, $key);
                }
            }
            $this->xmlInfo[$entity->name] = $info;
            $cacheNodes->append($cacheNode);
        }
        $this->addCacheNodes($cacheNodes);
    }

    private function createCacheNodeFromXMLElement(DOMElement $element): XMLObjectStoreCacheNode
    {
        $entityName = $element->getAttribute('name');
        /** @var EntityDescription $entity */
        $entity = $this->entitiesForConfiguration[$entityName];
        $referenceObject = $element->getAttribute('id');
        $objectID = $this->objectID($entity, (int)$referenceObject);
        return new XMLObjectStoreCacheNode($element, $objectID);
    }

    /**
     * @throws Exception
     */
    public function newCacheNode(ManagedObject $object): AtomicStoreCacheNode
    {
        $document = $this->document();
        $objectID = $object->objectID;
        $parent = $document->getElementsByTagName('elements')->item(0);
        $element = $document->createElement('element');
        $element->setAttribute('id', (string)$objectID->referenceObject);
        $element->setAttribute('name', $objectID->entityName);
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
            if (!$attribute->isTransient && !$attribute instanceof DerivedAttributeDescription) {
                $value = $this->getXMLAttributeValueFromObject($object, $attribute);
                if ($value !== null) {
                    $this->createAttributeChildOnNode($node, $attribute, $value);
                }
            }
        }
        foreach ($object->entity->relationshipsByName as $key => $relationship) {
            $value = $object->primitiveValueForKey($key);
            $relationshipNode = $this->createRelationshipChildOnNode($node, $relationship);
            $destinationNode = $relationshipNode->getAttributeNode('destination');
            $referencesNode = $relationshipNode->getAttributeNode('references');
            $referencesNode->value = $this->getIDRefString($value, $relationship);
            $inverseRelationship = $relationship->inverseRelationship;
            /** @var Set<ManagedObjectID> $managedObjectIDs */
            $managedObjectIDs = $value instanceof Set ? $value->map(fn(ManagedObject|ManagedObjectID $e): ManagedObjectID => $e instanceof ManagedObject ? $e->objectID : $e) : ($value instanceof ManagedObjectID ? new Set([$value]) : new Set());
            foreach ($managedObjectIDs as $managedObjectID) {
                $destinationNode->value = $managedObjectID->entityName;
                $cacheNode = $this->cacheNode($managedObjectID);
                if ($cacheNode instanceof XMLObjectStoreCacheNode) {
                    $relationshipNode = $this->createRelationshipChildOnNode($cacheNode->data, $inverseRelationship);
                    $referencesNode = $relationshipNode->getAttributeNode('references');
                    $references = new Set(explode(' ', $relationshipNode->getAttribute('references')));
                    $references->formUnion(new Set(explode(' ', $this->getIDRefString($object, $inverseRelationship))));
                    $referencesNode->value = trim($references->join(' '));
                    $destinationNode = $relationshipNode->getAttributeNode('destination');
                    $destinationNode->value = $object->entity->name;
                }
            }
        }
        $node->normalize();
    }

    private function getIDRefString(Set|ManagedObject|ManagedObjectID|Nil|null $value, ?RelationshipDescription $relationship = null): string
    {
        return $this->managedObjectIDs($value, $relationship)->map(fn(ManagedObjectID $objectID): string|int => $objectID->referenceObject)->join(' ');
    }

    private function managedObjectIDs(/** @noinspection PhpUnusedParameterInspection */ Set|ManagedObject|ManagedObjectID|Nil|null $value, ?RelationshipDescription $relationship = null): Set
    {
        /** @var Set<ManagedObjectID> $managedObjectIDs */
        $managedObjectIDs = new Set();
        if ($value instanceof Set) {
            $managedObjectIDs->appendContentsOf($value->map(fn(ManagedObject $object): ManagedObjectID => $object->objectID));
        } elseif ($value instanceof ManagedObject) {
            $managedObjectIDs->append($value->objectID);
        } elseif ($value instanceof ManagedObjectID) {
            $managedObjectIDs->append($value);
        }
        return $managedObjectIDs;
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    private function createRelationshipChildOnNode(DOMNode $node, RelationshipDescription $relationship): DOMElement
    {
        assert($node instanceof DOMElement);
        if (!($element = (new ArrayClass($node->getElementsByTagName('relationship')))->first(fn(DOMElement $element): bool => $element->getAttribute('name') === $relationship->name))) {
            $destinationEntity = $relationship->destinationEntity;
            $document = $this->document();
            $element = $document->createElement('relationship');
            assert($element instanceof DOMElement);
            $element->setAttribute('name', $relationship->name);
            $element->setAttribute('type', "$relationship->minCount/$relationship->maxCount");
            $element->setAttribute('destination', $destinationEntity->name);
            $element->setAttribute('references', '');
            $node->appendChild($element);
        }
        return $element;
    }

    /** @noinspection PhpUnhandledExceptionInspection */
    private function createAttributeChildOnNode(DOMNode $node, AttributeDescription $attribute, ?string $value = null): void
    {
        assert($node instanceof DOMElement);
        if (!($element = (new ArrayClass($node->getElementsByTagName('attribute')))->first(fn(DOMElement $element): bool => $element->getAttribute('name') === $attribute->name))) {
            $element = $this->document()->createElement('attribute', $value ?? '');
            assert($element instanceof DOMElement);
            $element->setAttribute('name', $attribute->name);
            $element->setAttribute('type', $attribute->type->name);
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
                $value = json_encode($value, JSON_THROW_ON_ERROR);
                break;
            case AttributeType::uri:
            case AttributeType::uuid:
            case AttributeType::binaryData:
            case AttributeType::date:
            case AttributeType::string:
            case AttributeType::objectID:
            case AttributeType::transformable:
                $value = ManagedObject::coercedValue($value, $attribute->type);
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
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = true;
        $path = $this->url->path;
        if (FileManager::default()->fileExists($path)) {
            $options = 0;
            if ($this->options?->valueForKey(ValidateXMLStoreOption)) {
                $options |= LIBXML_DTDLOAD;
            }
            $document->load($path, $options);
            return $document;
        }
        //TODO: review this structure
        $type = $document->createElement(StoreTypeKey, $this->type());
        $uuid = $document->createElement(StoreUUIDKey, $this->identifier);
        $metadata = $document->createElement('metadata');
        $metadata->appendChild($uuid);
        $metadata->appendChild($type);
        $model = $document->createElement('model');
        $model->appendChild($metadata);
        $parent = $document->createElement('elements');
        $model->appendChild($parent);
        $document->appendChild($model);
        return $document;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function retainedXmlInfoForRelationship(RelationshipDescription $relationship): mixed // @phpstan-ignore-line
    {
        return $this->xmlInfo->valueForKey($relationship->entity->name)?->valueForKey($relationship->name);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function xmlInfoForAttribute(AttributeDescription $attribute): mixed // @phpstan-ignore-line
    {
        return $this->xmlInfo->valueForKey($attribute->entity->name)?->valueForKey($attribute->name);
    }

    /**
     * @throws Exception
     */
    public function updateCacheNode(AtomicStoreCacheNode $node, ManagedObject $object): void
    {
        $entity = $object->entity;
        foreach ($entity->attributesByName as $key => $attribute) {
            if (!$attribute->isTransient) {
                $value = $object->primitiveValueForKey($key);
                $node->setValueForKey($value, $key);
            }
        }
        foreach ($entity->relationshipsByName as $key => $relationship) {
            $value = $object->primitiveValueForKey($key);
            $managedObjectIDs = $this->managedObjectIDs($value, $relationship);
            $value = $relationship->isToMany ? $managedObjectIDs : $managedObjectIDs->first();
            $node->setValueForKey($value, $key);
        }
        if ($node instanceof XMLObjectStoreCacheNode) {
            $this->updateXMLNode($node->data, $object);
        }
    }

    public function willRemoveCacheNodes(Set $cacheNodes): void
    {
        $document = $this->document();
        $model = $document->getElementsByTagName('model')->item(0);
        assert($model instanceof DOMElement);
        $parent = $model->getElementsByTagName('elements')->item(0);
        assert($parent instanceof DOMElement);
        /** @var ArrayClass<DOMElement> $children */
        $children = new ArrayClass($parent->getElementsByTagName('element'));
        /** @var AtomicStoreCacheNode $cacheNode */
        foreach ($cacheNodes as $cacheNode) {
            if ($cacheNode instanceof XMLObjectStoreCacheNode) {
                $entity = $cacheNode->objectID->entity;
                /** @var ArrayClass<DOMElement> $deletedElements */
                $deletedElements = $children->filter(fn(DOMElement $element): bool => $element->isSameNode($cacheNode->data));
                /** @var DOMElement $deletedElement */
                foreach ($deletedElements as $deletedElement) {
                    $relationshipElements = $deletedElement->getElementsByTagName('relationship');
                    foreach ($relationshipElements as $relationshipElement) {
                        /** @var string $name */
                        $name = $relationshipElement->getAttribute('name');
                        $relationship = $entity->relationshipsByName[$name] ?? throw new InternalInconsistencyException();
                        /** @psalm-suppress PossiblyNullPropertyFetch */
                        if ($relationship->deleteRule === DeleteRule::cascadeDeleteRule) {
                            /** @var string $destination */
                            $destination = $relationshipElement->getAttribute('destination');
                            $references = new Set(explode(' ', $relationshipElement->getAttribute('references')));
                            foreach ($references as $reference) {
                                $remainingElements = $children->filter(fn(DOMElement $element): bool => $element->getAttribute('name') === $destination && $element->getAttribute('id') === $reference);
                                foreach ($remainingElements as $remainingElement) {
                                    $parent->removeChild($remainingElement);
                                }
                            }
                        }
                    }
                    $reference = (string)$cacheNode->objectID->referenceObject;
                    foreach ($children as $element) {
                        $relationshipElements = $element->getElementsByTagName('relationship');
                        foreach ($relationshipElements as $relationshipElement) {
                            if ($entity->name === $relationshipElement->getAttribute('destination')) {
                                $references = new Set(explode(' ', $relationshipElement->getAttribute('references')));
                                if ($references->containsElement($reference)) {
                                    $references->remove($reference);
                                    $referencesNode = $relationshipElement->getAttributeNode('references');
                                    $referencesNode->value = $references->join(' ');
                                }
                            }
                        }
                    }
                    $parent->removeChild($deletedElement);
                }
            }
        }
    }

    public function willRemove(PersistentStoreCoordinator $coordinator): void
    {
    }

    public function type(): string
    {
        return XMLStoreType;
    }

    public function document(): DOMDocument
    {
        if ($this->document === null) {
            $this->document = $this->createDocument();
        }
        return $this->document;
    }

    public function load(): bool
    {
        $document = $this->document();
        $metadata = self::loadMetadataFromDocument($document);
        $this->metadata = $metadata;
        $this->identifier = $metadata[StoreUUIDKey];
        $this->loadFromDocument($document);
        return true;
    }

    public function save(): bool
    {
        return (bool)$this->document()->save($this->url->path);
    }
}
