<?php

/**
 * Konfigurasi CTO CRUD Application
 *
 * File ini mengatur berbagai pengaturan untuk aplikasi CTO CRUD termasuk:
 * - Cache TTL untuk skema database dan dashboard
 * - Pengaturan widget kapasitas dan threshold
 * - Konfigurasi ekspor dan pencarian
 * - Notifikasi Telegram dan monitoring
 *
 * Semua nilai dapat di-override melalui environment variables (.env).
 *
 * @package Config
 * @author CTO CRUD System
 */

return [
    /**
     * Cache TTL untuk data skema dan introspeksi database (detik)
     */
    'schema_cache_ttl' => env('CTO_SCHEMA_CACHE_TTL', 300),

    /**
     * Cache TTL untuk probe timestamp terbaru (detik)
     * Mengurangi frekuensi query MAX() yang berat
     */
    'dashboard_latest_cache_ttl' => env('CTO_DASHBOARD_LATEST_CACHE_TTL', 10800),

    /**
     * Cache TTL untuk data time-series S1 DL (detik)
     * Default: 3 jam (10800 detik)
     */
    'dashboard_series_cache_ttl' => env('CTO_DASHBOARD_SERIES_CACHE_TTL', 10800),

    /**
     * Cache TTL untuk payload traffic gabungan (detik)
     * Menggabungkan data series dan sites
     */
    'dashboard_traffic_cache_ttl' => env('CTO_DASHBOARD_TRAFFIC_CACHE_TTL', 10800),

    /**
     * Cache TTL untuk data sites dashboard (detik)
     * Default: 6 jam (21600 detik)
     */
    'dashboard_sites_cache_ttl' => env('CTO_DASHBOARD_SITES_CACHE_TTL', 21600),

    /**
     * Cache TTL untuk map enrichment kapasitas dari mysql3 (detik)
     */
    'dashboard_capacity_enrich_cache_ttl' => env('CTO_DASHBOARD_CAPACITY_ENRICH_CACHE_TTL', 10800),

    /**
     * Cache TTL untuk data pie chart order summary (detik)
     * Di-key berdasarkan tanggal terbaru
     */
    'dashboard_order_summary_cache_ttl' => env('CTO_DASHBOARD_ORDER_SUMMARY_CACHE_TTL', 10800),

    /**
     * Cache TTL untuk list/export JSON usulan order (detik)
     * Default: 30 menit (1800 detik)
     */
    'dashboard_usulan_order_list_cache_ttl' => env('CTO_DASHBOARD_USULAN_ORDER_LIST_CACHE_TTL', 1800),

    /**
     * Cache TTL untuk widget kapasitas DL mendekati maksimum (detik)
     
     */
    'capacity_cache_ttl' => env('CTO_CAPACITY_CACHE_TTL', 300),

    /**
     * Jumlah minggu default untuk analisis kapasitas
     */
    'capacity_weeks_default' => env('CTO_CAPACITY_WEEKS', 5),

    /**
     * Threshold default untuk kapasitas (0.0 - 1.0)
     * 0.85 = 85% dari kapasitas maksimum
     */
    'capacity_threshold_default' => env('CTO_CAPACITY_THRESHOLD', 0.85),

    /**
     * Modulo sampling untuk development (CRC32)
     * Hanya digunakan di non-production untuk membatasi data.
     * Format: crc32(site_id) % N = 0
     * - Set 0/null untuk disable
     * - Rekomendasi: 10-50 di local dev (10 = ~10%, 20 = ~5%)
     * - Production: disabled (0)
     */
    'capacity_sample_modulo' => env('CTO_CAPACITY_SAMPLE_MODULO', env('APP_ENV') === 'production' ? 0 : 2),

    /**
     * Limit default untuk jumlah record kapasitas
     */
    'capacity_limit_default' => env('CTO_CAPACITY_LIMIT', 5000),

    /**
     * Region untuk metrik packet loss
     */
    'packet_loss_region' => env('CTO_PACKET_LOSS_REGION', 'BALI NUSRA'),

    /**
     * Limit hasil pencarian untuk async FK select
     */
    'fk_search_limit' => env('CTO_FK_SEARCH_LIMIT', 50),

    /**
     * Ukuran chunk untuk ekspor CSV streaming
     */
    'export_chunk' => env('CTO_EXPORT_CHUNK', 500),

];
