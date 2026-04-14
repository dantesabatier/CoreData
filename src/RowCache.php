<?php

namespace Sabatier\CoreData;

use Override;

abstract class RowCache implements PersistentStoreCache
{
    protected function cacheKey(ManagedObjectID $objectID, ?RelationshipDescription $relationship = null): string
    {
        $name = $relationship?->name ?? "";
        $divider = $relationship ? "/" : "";
        return "{$objectID->uriRepresentation()->absoluteString}$divider$name";
    }

    #[Override]
    public function queryKeyForRequest(FetchRequest $request, QueryGenerationToken $token): string
    {
        $hash = urlencode($request->canonicalDescription);
        return "/query/$token->origin/$token->generation/$hash";
    }
}
