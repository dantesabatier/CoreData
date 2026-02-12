<?php

namespace Sabatier\CoreData;

/** @internal */
final class SQLSnapshotMapper extends SnapshotMapper
{
    protected StoreMetadataPruner $metadataPruner {
        get => $this->metadataPruner ??= new SQLStoreMetadataPruner();
    }

    protected StoreAttributeMapper $storeAttributeMapper {
        get => $this->storeAttributeMapper ??= new SQLStoreAttributeMapper($this->store, $this->context);
    }
}
