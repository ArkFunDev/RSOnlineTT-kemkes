<?php
/**
 * Titik masuk bersama untuk semua script: memuat helper, .env, config, klien API.
 */
define('ROOT_DIR', dirname(__DIR__));

require_once __DIR__ . '/helpers.php';
load_env(ROOT_DIR . '/.env');
require_once ROOT_DIR . '/config.php';
require_once __DIR__ . '/rsonline.php';

date_default_timezone_set(APP_TZ);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);
