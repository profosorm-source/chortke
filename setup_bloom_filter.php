<?php

/**
 * Script: Setup Bloom Filter برای CommonPasswords
 * 
 * استفاده:
 * php setup_bloom_filter.php
 * 
 * این script:
 * 1. Bloom Filter را از 95 پسورد می‌سازد
 * 2. در Redis/Cache ذخیره می‌کند (7 روز)
 * 3. سپس توی isCommonBloomFilter() استفاده می‌شود
 */

require_once __DIR__ . '/bootstrap/app.php';

echo "🔐 CommonPasswords - Bloom Filter Setup\n";
echo "=====================================\n\n";

// فقط یک خط!
\App\Data\CommonPasswords::setupBloomFilterInCache();

echo "\n✅ تمام!\n";
