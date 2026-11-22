<?php
/**
 * Form manual untuk mengirim (POST) satu data tempat tidur ke RS Online.
 * Berguna untuk uji coba / koreksi cepat. Pengiriman rutin memakai kirimdatakamar.php.
 */
require_once __DIR__ . '/src/bootstrap.php';
require_local_access();

function post($key, $default = '') { return isset($_POST[$key]) ? $_POST[$key] : $default; }

$is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');
$errors  = [];
$result  = null;

if ($is_post) {
    csrf_check();

    $required = [
        'id_tt'        => 'ID Tempat Tidur wajib diisi.',
        'ruang'        => 'Nama Ruang wajib diisi.',
        'jumlah_ruang' => 'Jumlah Ruang wajib diisi.',
        'jumlah'       => 'Jumlah TT wajib diisi.',
        'terpakai'     => 'Terpakai wajib diisi.',
    ];
    foreach ($required as $k => $msg) {
        if (trim((string)post($k)) === '') $errors[] = $msg;
    }

    if (!$errors) {
        $optional = ['terpakai_suspek', 'terpakai_konfirmasi', 'antrian', 'prepare', 'prepare_plan', 'covid'];
        $data = [];
        foreach (array_keys($required) as $k) $data[$k] = trim((string)post($k));
        foreach ($optional as $k)             $data[$k] = trim((string)post($k, '0'));

        $resp = rsonline_request('POST', json_encode($data));
        $dec  = json_decode($resp['resp'], true);
        $result = ['http_code' => $resp['http_code'], 'curl_error' => $resp['curl_err'],
                   'response' => ($dec !== null) ? $dec : $resp['resp'], 'ok' => $resp['ok']];
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kirim Data Kamar - RS Online</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#eef2f7;min-height:100vh;padding:24px 16px}
.wrap{max-width:620px;margin:0 auto}
h2{color:#1a56db;font-size:1.15rem;margin-bottom:16px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.09);padding:20px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}
.group{display:flex;flex-direction:column;gap:4px}
label{font-size:.78rem;font-weight:600;color:#374151}label span{font-weight:400;color:#9ca3af;font-size:.72rem;margin-left:4px}
input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.9rem;background:#fafafa}
input:focus{outline:none;border-color:#1a56db;box-shadow:0 0 0 3px rgba(26,86,219,.13);background:#fff}
.hint{font-size:.7rem;color:#9ca3af}.divider{grid-column:1/-1;border:none;border-top:1px dashed #e5e7eb;margin:4px 0}
.btn{width:100%;margin-top:16px;padding:11px;background:#1a56db;color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:700;cursor:pointer}
.btn:hover{background:#1648c7}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:.85rem;line-height:1.5}
.alert-err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.alert-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.alert-warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}
pre{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;font-size:.75rem;overflow:auto;margin-top:8px;max-height:200px;white-space:pre-wrap;word-break:break-all}
</style>
</head>
<body><div class="wrap"><div class="card">
  <h2>Kirim Data Kamar &mdash; RS Online Kemenkes</h2>

  <?php if ($errors): ?>
    <div class="alert alert-err"><?php foreach ($errors as $err): ?>&#9888; <?php echo h($err); ?><br><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($result !== null): ?>
    <div class="alert <?php echo $result['ok'] ? 'alert-ok' : 'alert-warn'; ?>">
      <strong><?php echo $result['ok'] ? '&#9989; Data berhasil dikirim!' : '&#9888; Ada masalah saat pengiriman.'; ?></strong>
      (HTTP <?php echo (int)$result['http_code']; ?>)
      <?php if ($result['curl_error'] !== ''): ?><br>cURL: <?php echo h($result['curl_error']); ?><?php endif; ?>
      <pre><?php echo h(json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
    </div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
    <div class="grid">
      <div class="group"><label>ID Tempat Tidur <span>(id_tt)</span></label>
        <input type="text" name="id_tt" value="<?php echo h(post('id_tt')); ?>" placeholder="Cth: 3" required>
        <span class="hint">Dari master referensi TT di SIRS</span></div>
      <div class="group"><label>Nama Ruang <span>(ruang)</span></label>
        <input type="text" name="ruang" value="<?php echo h(post('ruang')); ?>" placeholder="Cth: Irina A Bedah" required></div>
      <div class="group"><label>Jumlah Ruang <span>(jumlah_ruang)</span></label>
        <input type="number" name="jumlah_ruang" value="<?php echo h(post('jumlah_ruang')); ?>" min="0" required></div>
      <div class="group"><label>Jumlah TT <span>(jumlah)</span></label>
        <input type="number" name="jumlah" value="<?php echo h(post('jumlah')); ?>" min="0" required></div>
      <div class="group"><label>Terpakai <span>(terpakai)</span></label>
        <input type="number" name="terpakai" value="<?php echo h(post('terpakai')); ?>" min="0" required></div>
      <div class="group"><label>Antrian <span>(antrian)</span></label>
        <input type="number" name="antrian" value="<?php echo h(post('antrian', '0')); ?>" min="0"></div>
      <hr class="divider">
      <div class="group"><label>Terpakai Suspek <span>(terpakai_suspek)</span></label>
        <input type="number" name="terpakai_suspek" value="<?php echo h(post('terpakai_suspek', '0')); ?>" min="0"></div>
      <div class="group"><label>Terpakai Konfirmasi <span>(terpakai_konfirmasi)</span></label>
        <input type="number" name="terpakai_konfirmasi" value="<?php echo h(post('terpakai_konfirmasi', '0')); ?>" min="0"></div>
      <div class="group"><label>Prepare <span>(prepare)</span></label>
        <input type="number" name="prepare" value="<?php echo h(post('prepare', '0')); ?>" min="0"></div>
      <div class="group"><label>Prepare Plan <span>(prepare_plan)</span></label>
        <input type="number" name="prepare_plan" value="<?php echo h(post('prepare_plan', '0')); ?>" min="0"></div>
      <div class="group full"><label>COVID <span>(covid) &mdash; 0 = Non-COVID, 1 = COVID</span></label>
        <input type="number" name="covid" value="<?php echo h(post('covid', '0')); ?>" min="0" max="1">
        <span class="hint">Jika covid=1, isi terpakai_suspek &amp; terpakai_konfirmasi dengan benar</span></div>
    </div>
    <button type="submit" class="btn">Kirim Data ke RS Online</button>
  </form>
</div></div></body></html>
