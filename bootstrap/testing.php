<?php

declare(strict_types=1);

// Disable session sending in CLI
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');

// Bootstrap app.php
require_once dirname(__DIR__) . '/bootstrap/app.php';
