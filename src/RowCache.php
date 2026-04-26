<?php

declare(strict_types=1);

namespace Sabatier\CoreData;

use Override;

/**
 * Base implementation for row-level L2 cache.
 *
 * Provides a shared abstraction for concrete cache backends such as memory,
 * APCu, Redis, etc. This class may contain shared logic such as key generation,
 * serialization, namespacing and generation handling.
 *
 * Subclasses must implement the actual storage and retrieval mechanisms.
 */
abstract class RowCache implements PersistentStoreCache
{
    protected function cacheKey(ManagedObjectID $objectID, ?PropertyDescription $property = null): string
    {
        $name = $property?->name ?? "";
        $divider = $property ? "/" : "";
        return "{$objectID->uriRepresentation()->absoluteString}$divider$name";
    }

    #[Override]
    public function queryKeyForRequest(FetchRequest $request, QueryGenerationToken $token): string
    {
        $hash = urlencode($request->canonicalDescription);
        return "/query/$token->origin/$token->generation/$hash";
    }
}
