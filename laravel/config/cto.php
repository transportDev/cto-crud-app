<?php

return [
    // Cache TTL for schema/introspection data (seconds)
    'schema_cache_ttl' => env('CTO_SCHEMA_CACHE_TTL', 300),

    // Dashboard cache TTLs (seconds)
    // Time-series data is not highly time-sensitive; default 3 minutes.
    'dashboard_series_cache_ttl' => env('CTO_DASHBOARD_SERIES_CACHE_TTL', 180),
    // Site list can be cached longer; default 10 minutes.
    'dashboard_sites_cache_ttl' => env('CTO_DASHBOARD_SITES_CACHE_TTL', 600),

    // Limit for async FK select search results
    'fk_search_limit' => env('CTO_FK_SEARCH_LIMIT', 50),

    // CSV export chunk size
    'export_chunk' => env('CTO_EXPORT_CHUNK', 500),
];
