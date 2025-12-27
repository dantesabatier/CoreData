<?php

namespace Sabatier\CoreData;

use Sabatier\Foundation\CollectionDifference;
use Sabatier\Foundation\IndexPath;

/**
 * A delegate protocol that describes the methods that will be called by the associated fetched results controller when the fetch results have changed.
 */
interface FetchedResultsControllerDelegate
{
    /**
     * Notifies the receiver about changes to the content in the fetched results controller by using a collection difference.
     *
     * To apply the changes, call {@see applySnapshotAnimatingDifferences()} on the collection or table view’s data source.
     * If this method is implemented, no other delegate methods are invoked.
     * @param FetchedResultsController $controller
     * @param mixed $snapshot
     */
    public function controllerDidChangeContentWithSnapshot(FetchedResultsController $controller, mixed $snapshot): void;

    /**
     * Notifies the receiver about changes to the content in the fetched results controller by using a collection difference.
     *
     * This method is only invoked if the controller’s {@see FetchedResultsController::$sectionNameKeyPath} property is null and {@see controllerDidChangeContentWithSnapshot()} is not implemented.
     * If this method is implemented, no other delegate methods are invoked.
     * @param FetchedResultsController $controller
     * @param CollectionDifference $diff
     */
    public function controllerDidChangeContentWithDifference(FetchedResultsController $controller, CollectionDifference $diff): void;

    /**
     * Notifies the receiver that the fetched results controller is about to start processing of one or more changes due to an add, remove, move, or update.
     *
     * This method is invoked before all invocations of controllerDidChangeObjectAtIndexPath() and controllerDidChangeSection() have been sent for a given change event (such as the controller receiving a {@see ManagedObjectContextObjectsDidChange} notification).
     * @param FetchedResultsController $controller The fetched results controller that sent the message.
     */
    public function controllerWillChangeContent(FetchedResultsController $controller): void;

    /**
     * Notifies the receiver that a fetched object has been changed due to an add, remove, move, or update.
     *
     * The fetched results controller reports changes to its section before changes to the fetch result objects.
     * Changes are reported with the following heuristics:
     * On add and remove operations, only the added/removed object is reported.
     * It’s assumed that all objects that come after the affected object are also moved, but these moves are not reported.
     * A move is reported when the changed attribute on the object is one of the sort descriptors used in the fetch request.
     * An update of the object is assumed in this case, but no separate update message is sent to the delegate.
     * An update is reported when an object’s state changes, but the changed attributes aren’t part of the sort keys.
     * This method may be invoked many times during an update event (for example, if you are importing data on a background thread and adding them to the context in a batch). You should consider carefully whether you want to update the table view on receipt of each message.
     * @param FetchedResultsController $controller The fetched results controller that sent the message.
     * @param mixed $object The object in controller’s fetched results that changed.
     * @param IndexPath|null $indexPath The index path of the changed object (this value is null for insertions).
     * @param FetchedResultsChangeType $type The type of change.
     * @param IndexPath|null $newIndexPath The destination path for the object for insertions or moves (this value is null for a deletion).
     */
    public function controllerDidChangeObject(FetchedResultsController $controller, mixed $object, ?IndexPath $indexPath, FetchedResultsChangeType $type, ?IndexPath $newIndexPath): void;

    /**
     * Notifies the receiver of the addition or removal of a section.
     *
     * The fetched results controller reports changes to its section before changes to the fetched result objects.
     * This method may be invoked many times during an update event (for example, if you are importing data on a background thread and adding them to the context in a batch). You should consider carefully whether you want to update the table view on receipt of each message.
     * @param FetchedResultsController $controller The fetched results controller that sent the message.
     * @param FetchedResultsSectionInfo $sectionInfo The section that changed.
     * @param int $sectionIndex The index of the changed section.
     * @param FetchedResultsChangeType $type The type of change (insert or delete).
     */
    public function controllerDidChangeSection(FetchedResultsController $controller, FetchedResultsSectionInfo $sectionInfo, int $sectionIndex, FetchedResultsChangeType $type): void;

    /**
     * Notifies the receiver that the fetched results controller has completed processing of one or more changes due to an add, remove, move, or update.
     *
     * This method is invoked after all invocations of {@see controllerDidChangeObject()} and {@see controllerDidChangeSection()} have been sent for a given change event (such as the controller receiving a {@see ManagedObjectContextDidSave} notification).
     * @param FetchedResultsController $controller The fetched results controller that sent the message.
     */
    public function controllerDidChangeContent(FetchedResultsController $controller): void;

    /**
     * Returns the name for a given section.
     *
     * This method does not enable change tracking. It is only necessary if a section index is used.
     * If the delegate doesn't implement this method, the default implementation returns the capitalized first letter of the section name ({@see FetchedResultsController::sectionIndexTitle()} in FetchedResultsController).
     * @param FetchedResultsController $controller The fetched results controller that sent the message.
     * @param string $sectionName The default name of the section.
     * @return string The string to use as the name for the specified section.
     */
    public function controllerSectionIndexTitleForSectionName(FetchedResultsController $controller, string $sectionName): string;
}
