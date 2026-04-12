<?php

namespace Sabatier\CoreData;

use Override;

abstract class RowCache implements PersistentStoreCache
{
    protected function cacheKey(ManagedObjectID $objectID, ?RelationshipDescription $relationship): string
    {
        $name = $relationship?->name ?? "";
        $divider = $relationship ? "/" : "";
        return "{$objectID->uriRepresentation()->absoluteString}$divider$name";
    }

    #[Override]
    public function queryKeyForRequest(FetchRequest $request): string
    {
        return "/query/" . urlencode($request->canonicalDescription);
    }
}
