<?php
/**
 * Konfigurasi aplikasi. Semua nilai dibaca dari file .env (lihat .env.example),
 * sehingga tidak ada kredensial yang tertulis di dalam source code.
 */

// RS Online (Kemenkes)
define('RS_ID',    env_required('RS_ID'));     // header x-rs-id
define('RS_PASS',  env_required('RS_PASS'));   // header x-pass
define('BASE_URL', env('BASE_URL', 'https://sirs.kemkes.go.id/fo/index.php/Fasyankes'));

// Database SIMRS
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env_required('DB_USER'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_NAME', env_required('DB_NAME'));

// Aplikasi
define('APP_TZ',          env('APP_TZ', 'Asia/Jakarta'));
define('APP_DEBUG',       env_bool('APP_DEBUG', false));
define('ALLOW_REMOTE',    env_bool('ALLOW_REMOTE', false));
define('CURL_VERIFY_SSL', env_bool('CURL_VERIFY_SSL', true));
define('CURL_CAINFO',     env('CURL_CAINFO', ''));
