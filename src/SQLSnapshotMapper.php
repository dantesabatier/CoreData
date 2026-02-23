<?php

namespace Sabatier\CoreData;

use Override;

/** @internal */
final class SQLSnapshotMapper extends SnapshotMapper
{
    #[Override]
    protected StoreMetadataPruner $metadataPruner {
        get => $this->metadataPruner ??= new SQLStoreMetadataPruner();
    }
    #[Override]
    protected StoreAttributeMapper $storeAttributeMapper {
        get => $this->storeAttributeMapper ??= new SQLStoreAttributeMapper($this->store, $this->context);
    }
}
