<?php

/**
 * Search Configuration (C1 — CQRS Read-Model)
 *
 * use_projection:
 *   فعال‌سازی مسیر اصلی جستجو از روی Read-Model (`search_projections`).
 *   حتی اگر true باشد، هر Gateway قبل از استفاده isReady() را بررسی می‌کند؛
 *   بنابراین تا زمانی که Backfill اجرا نشده باشد، به‌صورت خودکار به مسیر live
 *   (که اکنون نیز از FULLTEXT استفاده می‌کند) برمی‌گردد. این رفتار، مهاجرت
 *   بدون downtime را تضمین می‌کند.
 *
 * cache_ttl:
 *   TTL پیش‌فرض کش نتایج جستجو (ثانیه) — توسط BaseSearchProvider استفاده می‌شود.
 */

return [
    'use_projection' => (bool) env('SEARCH_USE_PROJECTION', true),
    'cache_ttl'      => (int) env('SEARCH_CACHE_TTL', 300),
];
