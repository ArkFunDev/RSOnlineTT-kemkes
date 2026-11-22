<?php
/**
 * Helper umum (tanpa dependensi ke config, agar mudah diuji).
 */

// ─── Output ──────────────────────────────────────────────────────────────────
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function norm($v) {
    return trim((string)$v);
}

// ─── Environment (.env) ──────────────────────────────────────────────────────
function load_env($path) {
    if (!is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $_ENV[$k] = $v;
        putenv($k . '=' . $v);
    }
}

function env($key, $default = null) {
    if (isset($_ENV[$key])) return $_ENV[$key];
    $v = getenv($key);
    return ($v === false) ? $default : $v;
}

function env_required($key) {
    $v = env($key);
    if ($v === null || $v === '') {
        http_response_code(500);
        exit("Konfigurasi '$key' belum diisi. Salin .env.example menjadi .env lalu isi nilainya." . PHP_EOL);
    }
    return $v;
}

function env_bool($key, $default = false) {
    $v = env($key);
    if ($v === null || $v === '') return $default;
    return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
}

/** Muat config/<nama>.php (lokal, di-gitignore); jika belum ada, beri petunjuk. */
function load_local_config($name) {
    $file = ROOT_DIR . '/config/' . $name . '.php';
    if (!is_file($file)) {
        http_response_code(500);
        exit("File config/$name.php belum ada. Salin config/$name.example.php menjadi config/$name.php lalu sesuaikan." . PHP_EOL);
    }
    $data = require $file;
    return is_array($data) ? $data : [];
}

// ─── Keamanan Web UI ─────────────────────────────────────────────────────────
/** Batasi akses web ke localhost kecuali ALLOW_REMOTE=true. CLI selalu lolos. */
function require_local_access() {
    if (PHP_SAPI === 'cli') return;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (ALLOW_REMOTE || in_array($ip, ['127.0.0.1', '::1'], true)) return;
    http_response_code(403);
    exit('Akses ditolak: halaman ini hanya dapat dibuka dari localhost.');
}

function csrf_token() {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check() {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $sent = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(403);
        exit('Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

// ─── Database ────────────────────────────────────────────────────────────────
function db_connect() {
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_error) {
        error_log('[rsonline] Koneksi DB gagal: ' . $db->connect_error);
        http_response_code(500);
        exit('Koneksi database gagal. Periksa pengaturan DB_* pada .env.' . PHP_EOL);
    }
    $db->set_charset('utf8mb4');
    return $db;
}

// ─── Logika domain ───────────────────────────────────────────────────────────
/**
 * Perluas notasi rentang kode bed.
 *   "B1-B2" => [B1,B2]   "C4-C6" => [C4,C5,C6]   "1-2" => [1,2]   "Lt.1" => [Lt.1]
 */
function expand_range($kode) {
    $kode = trim($kode);
    if (strpos($kode, '-') === false) return [$kode];

    if (preg_match('/^([A-Za-z]+)(\d+)-[A-Za-z]*(\d+)$/', $kode, $m)) {
        $prefix = $m[1]; $from = (int)$m[2]; $to = (int)$m[3];
        if ($from > $to || $to - $from > 100) return [$kode];
        $out = [];
        for ($i = $from; $i <= $to; $i++) $out[] = $prefix . $i;
        return $out;
    }

    if (preg_match('/^(\d+)-(\d+)$/', $kode, $m)) {
        $from = (int)$m[1]; $to = (int)$m[2];
        if ($from > $to || $to - $from > 100) return [$kode];
        $out = [];
        for ($i = $from; $i <= $to; $i++) $out[] = (string)$i;
        return $out;
    }

    return [$kode];
}

/**
 * Hitung kapasitas/terpakai/kosong. Terpakai tidak boleh melebihi kapasitas
 * master (SK), dan semua nilai tidak boleh negatif.
 * @return int[] [jumlah, terpakai, kosong]
 */
function compute_occupancy($jumlah_master, $terpakai_lokal) {
    $jumlah   = max(0, (int)$jumlah_master);
    $terpakai = min(max(0, (int)$terpakai_lokal), $jumlah);
    $kosong   = max(0, $jumlah - $terpakai);
    return [$jumlah, $terpakai, $kosong];
}
