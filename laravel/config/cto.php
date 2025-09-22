<?php

return [
    // Cache TTL for schema/introspection data (seconds)
    'schema_cache_ttl' => env('CTO_SCHEMA_CACHE_TTL', 300),

    // Dashboard cache TTLs (seconds)
    // Latest timestamp probe (reduce MAX() scans)
    'dashboard_latest_cache_ttl' => env('CTO_DASHBOARD_LATEST_CACHE_TTL', 10800),

    // Time-series (S1 DL) data cache; default 3 minutes.
    'dashboard_series_cache_ttl' => env('CTO_DASHBOARD_SERIES_CACHE_TTL', 10800),

    // Composed traffic payload cache (wraps series + sites)
    'dashboard_traffic_cache_ttl' => env('CTO_DASHBOARD_TRAFFIC_CACHE_TTL', 10800),

    'dashboard_sites_cache_ttl' => env('CTO_DASHBOARD_SITES_CACHE_TTL', 21600),

    // Capacity enrichment map (mysql3) cache
    'dashboard_capacity_enrich_cache_ttl' => env('CTO_DASHBOARD_CAPACITY_ENRICH_CACHE_TTL', 10800),

    // Order summary pie data cache (keyed by latest date)
    'dashboard_order_summary_cache_ttl' => env('CTO_DASHBOARD_ORDER_SUMMARY_CACHE_TTL', 10800),

    // Usulan Order list/export JSON cache TTL
    'dashboard_usulan_order_list_cache_ttl' => env('CTO_DASHBOARD_USULAN_ORDER_LIST_CACHE_TTL', 1800),

    // Capacity (DL near max) widget settings
    'capacity_cache_ttl' => env('CTO_CAPACITY_CACHE_TTL', 21600),

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
