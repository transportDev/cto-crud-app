<?php

return [
    // Cache TTL for schema/introspection data (seconds)
    'schema_cache_ttl' => env('CTO_SCHEMA_CACHE_TTL', 300),

    // Dashboard cache TTLs (seconds)
    // Time-series data is not highly time-sensitive; default 3 minutes.
    'dashboard_series_cache_ttl' => env('CTO_DASHBOARD_SERIES_CACHE_TTL', 180),
    // Site list can be cached longer; default 10 minutes.
    'dashboard_sites_cache_ttl' => env('CTO_DASHBOARD_SITES_CACHE_TTL', 600),

    // Capacity (DL near max) widget settings
    'capacity_cache_ttl' => env('CTO_CAPACITY_CACHE_TTL', 300), // seconds
    'capacity_weeks_default' => env('CTO_CAPACITY_WEEKS', 5),
    'capacity_threshold_default' => env('CTO_CAPACITY_THRESHOLD', 0.85),
    // Dev-only sampling via crc32(site_id) % N = 0. Set 0/null to disable.
    // Recommend 10..50 in local dev (10 => ~10%, 20 => ~5%).
    'capacity_sample_modulo' => env('CTO_CAPACITY_SAMPLE_MODULO', env('APP_ENV') === 'production' ? 0 : 2),
    'capacity_limit_default' => env('CTO_CAPACITY_LIMIT', 5000),

    // Packet loss metrics
    'packet_loss_region' => env('CTO_PACKET_LOSS_REGION', 'BALI NUSRA'),

    // Limit for async FK select search results
    'fk_search_limit' => env('CTO_FK_SEARCH_LIMIT', 50),

    // CSV export chunk size
    'export_chunk' => env('CTO_EXPORT_CHUNK', 500),
];
