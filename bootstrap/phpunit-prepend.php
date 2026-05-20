<?php

if (!isset($_SERVER['REQUEST_TIME_FLOAT'])) {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
}
