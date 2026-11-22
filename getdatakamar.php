<?php
/**
 * Ambil master data tempat tidur milik fasyankes dari RS Online (GET) dan
 * simpan ke data/fasyankes.json. File ini menjadi input import_rsonlineTT.php.
 *
 * CLI : php getdatakamar.php
 * Web : http://localhost/rsonline/getdatakamar.php
 */
require_once __DIR__ . '/src/bootstrap.php';
require_local_access();

$res  = rsonline_request('GET');
$json = json_decode($res['resp'], true);
$ok   = $res['ok'] && is_array($json) && isset($json['fasyankes']) && is_array($json['fasyankes']);

$saved = false;
if ($ok) {
    $dir = ROOT_DIR . '/data';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $saved = file_put_contents($dir . '/fasyankes.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

if (PHP_SAPI === 'cli') {
    if ($ok && $saved) {
        echo 'OK: ' . count($json['fasyankes']) . ' record disimpan ke data/fasyankes.json' . PHP_EOL;
        exit(0);
    }
    fwrite(STDERR, 'GAGAL: HTTP ' . $res['http_code'] . ' ' . $res['curl_err'] . PHP_EOL);
    exit(1);
}
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><title>Ambil Data Kamar</title>
<style>body{font-family:system-ui,sans-serif;background:#eef2f7;padding:24px}.c{max-width:640px;margin:auto;background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08)}.ok{color:#166534}.err{color:#991b1b}</style></head>
<body><div class="c">
<h2>Ambil Master Data Tempat Tidur</h2>
<?php if ($ok && $saved): ?>
  <p class="ok">&#10003; <?php echo count($json['fasyankes']); ?> record disimpan ke <code>data/fasyankes.json</code>.</p>
  <p>Langkah berikutnya: <a href="import_rsonlineTT.php">import_rsonlineTT.php</a> (dry-run).</p>
<?php else: ?>
  <p class="err">&#9888; Gagal mengambil data (HTTP <?php echo h($res['http_code']); ?>).</p>
  <?php if ($res['curl_err'] !== ''): ?><p>cURL: <?php echo h($res['curl_err']); ?></p><?php endif; ?>
<?php endif; ?>
</div></body></html>
