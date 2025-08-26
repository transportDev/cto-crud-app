<?php

return [
    // Cache TTL for schema/introspection data (seconds)
    'schema_cache_ttl' => env('CTO_SCHEMA_CACHE_TTL', 300),

    // Limit for async FK select search results
    'fk_search_limit' => env('CTO_FK_SEARCH_LIMIT', 50),

    // CSV export chunk size
    'export_chunk' => env('CTO_EXPORT_CHUNK', 500),
];
