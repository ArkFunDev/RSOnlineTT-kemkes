<?php
/**
 * Bridging ketersediaan tempat tidur: SIMRS (Khanza) -> RS Online / SIRS Kemenkes.
 *
 * CLI : php kirimdatakamar.php [debug|send]
 * Web : http://localhost/rsonline/kirimdatakamar.php   (debug = simulasi, send via tombol POST)
 *
 * Yang dikirim HANYA angka agregat per ruang (kapasitas & terpakai).
 * Tidak ada data identitas pasien yang dibaca maupun dikirim.
 */
require_once __DIR__ . '/src/bootstrap.php';
set_time_limit(0);

$is_cli = (PHP_SAPI === 'cli');
$mode   = 'debug';

if ($is_cli) {
    if (isset($argv[1])) $mode = strtolower(trim($argv[1]));
} else {
    require_local_access();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'send') {
        csrf_check();
        $mode = 'send';
    }
}
if (!in_array($mode, ['debug', 'send'], true)) $mode = 'debug';

$filter = load_local_config('filter');
$db     = db_connect();

$waktu_kirim = date('Y-m-d H:i:s');
$tgl_file    = date('Y-m-d');

// ─── Query agregasi: bed lokal -> ruang master Kemenkes ──────────────────────
$params = [];
$types  = '';
$extra  = '';

if (!empty($filter['blocked_id_t_tt'])) {
    $extra .= ' AND rt.id_t_tt NOT IN (' . implode(',', array_fill(0, count($filter['blocked_id_t_tt']), '?')) . ')';
    foreach ($filter['blocked_id_t_tt'] as $id) { $params[] = (string)$id; $types .= 's'; }
}
if (!empty($filter['whitelist_prefixes'])) {
    $conds = [];
    foreach ($filter['whitelist_prefixes'] as $p) { $conds[] = 'rt.kd_kamar LIKE ?'; $params[] = $p; $types .= 's'; }
    $extra .= ' AND (' . implode(' OR ', $conds) . ')';
}

$sql = "
SELECT
    x.id_t_tt, x.id_tt, x.tt, x.ruang_master,
    MAX(x.jumlah_master) AS jumlah_master,
    COUNT(*) AS jml_bed_komponen,
    SUM(CASE WHEN x.pasien_aktif > 0 THEN 1 ELSE 0 END) AS terpakai_lokal,
    SUM(CASE WHEN x.pasien_aktif > 1 THEN 1 ELSE 0 END) AS bed_anomali,
    SUM(x.pasien_aktif) AS total_pasien_raw
FROM (
    SELECT
        rt.id_t_tt, rt.id_tt, rt.tt, rt.kd_kamar,
        JSON_UNQUOTE(JSON_EXTRACT(rt.last_response, '\$.ruang')) AS ruang_master,
        CAST(JSON_UNQUOTE(JSON_EXTRACT(rt.last_response, '\$.jumlah')) AS UNSIGNED) AS jumlah_master,
        COALESCE(ki.jml_pasien, 0) AS pasien_aktif
    FROM rsonlineTT rt
    INNER JOIN kamar k ON k.kd_kamar = rt.kd_kamar
    LEFT JOIN (
        /* hanya COUNT per kamar: tidak ada kolom identitas pasien */
        SELECT kd_kamar, COUNT(*) AS jml_pasien
        FROM kamar_inap
        WHERE (tgl_keluar IS NULL OR tgl_keluar = '' OR tgl_keluar = '0000-00-00' OR tgl_keluar = '0000-00-00 00:00:00')
          AND (stts_pulang IS NULL OR stts_pulang IN ('', '-', '+', 'Pindah Kamar', 'Status Belum Lengkap'))
        GROUP BY kd_kamar
    ) ki ON ki.kd_kamar = rt.kd_kamar
    WHERE rt.id_t_tt IS NOT NULL AND rt.id_t_tt <> ''
      AND rt.kd_kamar IS NOT NULL AND rt.kd_kamar <> ''
      AND k.statusdata = '1'
      $extra
) x
GROUP BY x.id_t_tt, x.id_tt, x.tt, x.ruang_master
ORDER BY CAST(x.id_t_tt AS UNSIGNED), x.ruang_master
";

$stmt = $db->prepare($sql);
if (!$stmt) { error_log('[rsonline] prepare gagal: ' . $db->error); exit('Query gagal disiapkan.' . PHP_EOL); }
if ($params) $stmt->bind_param($types, ...$params);
if (!$stmt->execute()) { error_log('[rsonline] execute gagal: ' . $stmt->error); exit('Query agregasi gagal.' . PHP_EOL); }
$q = $stmt->get_result();

$all_rows = $layak_kirim = $perlu_review = [];

while ($row = $q->fetch_assoc()) {
    $id_t_tt      = norm($row['id_t_tt']);
    $ruang_master = norm($row['ruang_master']);
    $jumlah_master = (int)$row['jumlah_master'];

    list($jumlah, $terpakai, $kosong) = compute_occupancy($jumlah_master, $row['terpakai_lokal']);

    $flags = [];
    if ($id_t_tt === '')                                                  $flags[] = 'ID_T_TT_KOSONG';
    if ($ruang_master === '' || strtolower($ruang_master) === 'null')     $flags[] = 'RUANG_MASTER_KOSONG';
    if ($jumlah_master <= 0)                                              $flags[] = 'JUMLAH_MASTER_INVALID (0)';

    // Payload FULL setiap eksekusi (bukan delta): RS Online menilai kesegaran data
    // per id_t_tt dari kiriman terbaru, jadi ruang yang tidak berubah tetap di-refresh.
    $payload = [
        'id_t_tt' => $id_t_tt, 'ruang' => $ruang_master, 'jumlah_ruang' => '0',
        'jumlah' => (string)$jumlah, 'terpakai' => (string)$terpakai,
        'terpakai_suspek' => '0', 'terpakai_konfirmasi' => '0',
        'antrian' => '0', 'prepare' => '0', 'prepare_plan' => '0',
        'covid' => 0, 'terpakai_dbd' => '0', 'terpakai_dbd_anak' => '0',
    ];

    // Snapshot state terakhir yang berhasil dikirim (disimpan ke rsonlineTT.last_response)
    $snapshot = [
        'id_tt' => norm($row['id_tt']), 'tt' => norm($row['tt']), 'ruang' => $ruang_master,
        'kode_siranap' => '', 'jumlah_ruang' => '0', 'jumlah' => (string)$jumlah,
        'terpakai_suspek' => '0', 'terpakai_konfirmasi' => '0', 'antrian' => '0',
        'prepare' => '0', 'prepare_plan' => '0', 'kosong' => (string)$kosong,
        'terpakai_dbd' => '0', 'terpakai_dbd_anak' => '0', 'terpakai' => (string)$terpakai,
        'covid' => '0', 'id_t_tt' => $id_t_tt, 'tglupdate' => $waktu_kirim,
    ];

    $item = [
        'id_t_tt' => $id_t_tt, 'id_tt' => norm($row['id_tt']), 'tt' => norm($row['tt']),
        'ruang_master' => $ruang_master, 'jumlah_master' => $jumlah_master,
        'jml_bed_komponen' => (int)$row['jml_bed_komponen'],
        'terpakai_lokal' => (int)$row['terpakai_lokal'],
        'bed_anomali' => (int)$row['bed_anomali'],
        'total_pasien_raw' => (int)$row['total_pasien_raw'],
        'jumlah' => $jumlah, 'terpakai' => $terpakai, 'kosong' => $kosong,
        'flags' => $flags,
        'payload'       => json_encode($payload,  JSON_UNESCAPED_UNICODE),
        'full_response' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
    ];

    $all_rows[] = $item;
    if (empty($flags)) $layak_kirim[] = $item; else $perlu_review[] = $item;
}
$stmt->close();

// ─── Rekapitulasi per kelas ──────────────────────────────────────────────────
$rekap_kelas = [];
$grand = ['kapasitas' => 0, 'terpakai' => 0, 'kosong' => 0];
foreach ($layak_kirim as $r) {
    $k = $r['tt'];
    if (!isset($rekap_kelas[$k])) $rekap_kelas[$k] = ['kapasitas' => 0, 'terpakai' => 0, 'kosong' => 0];
    $rekap_kelas[$k]['kapasitas'] += $r['jumlah'];
    $rekap_kelas[$k]['terpakai']  += $r['terpakai'];
    $rekap_kelas[$k]['kosong']    += $r['kosong'];
    $grand['kapasitas'] += $r['jumlah'];
    $grand['terpakai']  += $r['terpakai'];
    $grand['kosong']    += $r['kosong'];
}

// ─── Kirim ke RS Online ──────────────────────────────────────────────────────
$results = [];
$sukses = $gagal = 0;

if ($mode === 'send' && !empty($layak_kirim)) {
    $raw = rsonline_send_batch($layak_kirim, 'PUT', 20);
    $sukses_map = [];
    foreach ($raw as $i => $res) {
        if ($res['ok']) { $sukses++; $sukses_map[$layak_kirim[$i]['id_t_tt']] = $layak_kirim[$i]['full_response']; }
        else            { $gagal++; }
        $results[] = array_merge($layak_kirim[$i], $res);
    }

    // Catat state terakhir yang berhasil terkirim (hanya histori, bukan filter kiriman berikutnya)
    if ($sukses_map) {
        $upd = $db->prepare('UPDATE rsonlineTT SET last_response = ?, last_update = ?, is_synced = 1 WHERE id_t_tt = ?');
        foreach ($sukses_map as $id => $json) {
            $upd->bind_param('sss', $json, $waktu_kirim, $id);
            $upd->execute();
        }
        $upd->close();
    }
}
$db->close();

// ─── Log file ────────────────────────────────────────────────────────────────
$log_dir = ROOT_DIR . '/log';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/bridging_tt_' . $tgl_file . '.txt';

$line = str_repeat('=', 110) . PHP_EOL;
$log  = $line . "BRIDGING TEMPAT TIDUR RS ONLINE" . PHP_EOL;
$log .= "WAKTU         : {$waktu_kirim}" . PHP_EOL;
$log .= "MODE          : {$mode}" . PHP_EOL;
$log .= "TOTAL DATA    : " . count($all_rows) . PHP_EOL;
$log .= "LAYAK KIRIM   : " . count($layak_kirim) . PHP_EOL;
$log .= "PERLU REVIEW  : " . count($perlu_review) . PHP_EOL;
if ($mode === 'send') $log .= "SUKSES / GAGAL: {$sukses} / {$gagal}" . PHP_EOL;
$log .= $line . PHP_EOL . "REKAPITULASI PER KELAS" . PHP_EOL;
foreach ($rekap_kelas as $kls => $v) {
    $log .= sprintf("%-40s kapasitas=%-4s terpakai=%-4s kosong=%-4s", $kls, $v['kapasitas'], $v['terpakai'], $v['kosong']) . PHP_EOL;
}
$log .= sprintf("%-40s kapasitas=%-4s terpakai=%-4s kosong=%-4s", 'GRAND TOTAL', $grand['kapasitas'], $grand['terpakai'], $grand['kosong']) . PHP_EOL . PHP_EOL;

$log .= "DETAIL LAYAK KIRIM" . PHP_EOL;
foreach ($layak_kirim as $r) {
    $log .= sprintf("id_t_tt=%-8s tt=%-15s ruang=%-20s kapasitas=%s bed_lokal=%s terpakai=%s kosong=%s",
        $r['id_t_tt'], substr($r['tt'], 0, 15), substr($r['ruang_master'], 0, 20),
        $r['jumlah'], $r['jml_bed_komponen'], $r['terpakai'], $r['kosong']) . PHP_EOL;
}
$log .= PHP_EOL . "PERLU REVIEW (TIDAK DIKIRIM)" . PHP_EOL;
foreach ($perlu_review as $r) {
    $log .= sprintf("id_t_tt=%-8s ruang=%-20s master=%s bed=%s flags=%s",
        $r['id_t_tt'], substr($r['ruang_master'], 0, 20), $r['jumlah_master'], $r['jml_bed_komponen'], implode(',', $r['flags'])) . PHP_EOL;
}
if ($mode === 'send') {
    $log .= PHP_EOL . "HASIL KIRIM" . PHP_EOL;
    foreach ($results as $r) {
        $log .= sprintf("[%s] id_t_tt=%s HTTP=%s curl=%s resp=%s",
            $r['ok'] ? 'OK' : 'FAIL', $r['id_t_tt'], $r['http_code'], $r['curl_err'] ?: '-',
            substr(trim($r['resp']), 0, 160)) . PHP_EOL;
    }
}
file_put_contents($log_file, $log . PHP_EOL, FILE_APPEND | LOCK_EX);

if ($is_cli) {
    echo $log;
    exit($gagal > 0 ? 1 : 0); // exit code non-zero agar Task Scheduler/cron bisa mendeteksi kegagalan
}

$resmap = [];
foreach ($results as $rr) $resmap[$rr['id_t_tt'] . '|' . $rr['ruang_master']] = $rr;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bridging TT RS Online</title>
<style>
body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f0f2f5;margin:0;padding:12px}
.card{margin:auto;background:#fff;border-radius:8px;padding:15px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
h2{margin:0 0 5px;color:#1e293b;font-size:18px} p{margin:0 0 10px;color:#64748b;font-size:12px}
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:10px}
.summary{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 12px;min-width:110px}
.box strong{display:block;font-size:16px}.blue{color:#2563eb}.green{color:#16a34a}.red{color:#dc2626}.orange{color:#d97706}
small{color:#64748b;font-size:11px}
.tc{max-height:65vh;overflow-y:auto;border:1px solid #cbd5e1;border-radius:6px;margin-bottom:15px}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:11.5px}
th{background:#1e293b;color:#f8fafc;padding:8px 10px;text-align:left;position:sticky;top:0;white-space:nowrap}
td{padding:6px 10px;border-bottom:1px solid #f1f5f9;background:#fff} tr:hover td{background:#f1f5f9}
.badge{padding:3px 6px;border-radius:4px;font-size:10px;font-weight:700;display:inline-block;margin:1px}
.ok{background:#dcfce7;color:#166534}.warn{background:#fef9c3;color:#854d0e}.fail{background:#fee2e2;color:#991b1b}
.btn{display:inline-block;padding:6px 12px;border-radius:6px;text-decoration:none;color:#fff;background:#2563eb;font-size:12px;border:0;cursor:pointer}
.btn.warn{background:#ea580c;color:#fff}
pre{white-space:pre-wrap;word-break:break-word;margin:0;font-size:11px;color:#475569;max-width:300px}
.rekap{background:#eef2ff;border:1px solid #c7d2fe;padding:12px;border-radius:8px;margin-bottom:15px}
.rekap h3{margin:0 0 10px;font-size:13px;color:#3730a3}
.rg{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px}
.ri{background:#fff;padding:8px;border-radius:4px;border:1px solid #e0e7ff;font-size:11px}
.rt{background:#312e81;color:#fff;padding:10px;border-radius:6px;font-size:13px;font-weight:700;margin-top:10px;display:flex;justify-content:space-between}
</style>
</head>
<body>
<div class="card">
  <div class="toolbar">
    <div>
      <h2>Bridging Ketersediaan TT &mdash; RS Online (Full Sync)</h2>
      <p>Mode: <strong><?php echo h(strtoupper($mode)); ?></strong> &middot; <small>Log: log/<?php echo h(basename($log_file)); ?></small></p>
    </div>
    <div>
      <a class="btn" href="?">Debug (simulasi)</a>
      <form method="POST" style="display:inline" onsubmit="return confirm('Kirim data ke RS Online (LIVE)?')">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="mode" value="send">
        <button class="btn warn" type="submit">Send Kemenkes (Live)</button>
      </form>
    </div>
  </div>

  <div class="rekap">
    <h3>Rekapitulasi per Kelas</h3>
    <div class="rg">
    <?php foreach ($rekap_kelas as $kls => $v): ?>
      <div class="ri"><strong style="display:block;margin-bottom:4px"><?php echo h($kls); ?></strong>
        Kapasitas: <b style="color:#1d4ed8"><?php echo $v['kapasitas']; ?></b><br>
        Terpakai: <b style="color:#d97706"><?php echo $v['terpakai']; ?></b><br>
        Kosong: <b style="color:#16a34a"><?php echo $v['kosong']; ?></b></div>
    <?php endforeach; ?>
    </div>
    <div class="rt"><span>GRAND TOTAL</span>
      <span>KAPASITAS: <?php echo $grand['kapasitas']; ?> | TERPAKAI: <?php echo $grand['terpakai']; ?> | KOSONG: <?php echo $grand['kosong']; ?></span></div>
  </div>

  <div class="summary">
    <div class="box"><strong class="blue"><?php echo count($all_rows); ?></strong><small>Total ruang</small></div>
    <div class="box"><strong class="green"><?php echo count($layak_kirim); ?></strong><small>Layak kirim</small></div>
    <div class="box"><strong class="orange"><?php echo count($perlu_review); ?></strong><small>Perlu review</small></div>
    <?php if ($mode === 'send'): ?>
    <div class="box"><strong class="green"><?php echo $sukses; ?></strong><small>Sukses</small></div>
    <div class="box"><strong class="red"><?php echo $gagal; ?></strong><small>Gagal</small></div>
    <?php endif; ?>
  </div>

  <div class="tc"><table>
    <thead><tr><th>#</th><th>ID_T_TT</th><th>Kelas</th><th>Ruang (Master)</th><th>Kapasitas</th><th>Bed lokal</th><th>Terpakai</th><th>Kosong</th><th>Status</th>
      <?php if ($mode === 'send'): ?><th>HTTP</th><th>Respon</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($layak_kirim as $i => $r):
        $sr = $resmap[$r['id_t_tt'] . '|' . $r['ruang_master']] ?? null; ?>
      <tr>
        <td><?php echo $i + 1; ?></td>
        <td><?php echo h($r['id_t_tt']); ?></td>
        <td><?php echo h($r['tt']); ?></td>
        <td><?php echo h($r['ruang_master']); ?></td>
        <td><b><?php echo h($r['jumlah']); ?></b></td>
        <td><?php echo h($r['jml_bed_komponen']); ?></td>
        <td><b><?php echo h($r['terpakai']); ?></b></td>
        <td><b><?php echo h($r['kosong']); ?></b></td>
        <td>
          <?php if ($r['bed_anomali'] > 0): ?><span class="badge warn"><?php echo h($r['bed_anomali']); ?> bed terisi ganda (dihitung 1)</span><?php endif; ?>
          <?php if ($r['jml_bed_komponen'] > $r['jumlah']): ?><span class="badge warn">Over bed (dikunci ke kapasitas)</span>
          <?php else: ?><span class="badge ok">OK</span><?php endif; ?>
        </td>
        <?php if ($mode === 'send'): ?>
        <td><span class="badge <?php echo ($sr && $sr['ok']) ? 'ok' : 'fail'; ?>">HTTP <?php echo h($sr['http_code'] ?? '-'); ?></span></td>
        <td><pre><?php echo h(substr((string)($sr['resp'] ?? ''), 0, 130)); ?></pre>
            <?php if (!empty($sr['curl_err'])): ?><b style="color:#dc2626">cURL: <?php echo h($sr['curl_err']); ?></b><?php endif; ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if ($perlu_review): ?>
  <h3 style="margin:0 0 8px;font-size:14px">Perlu review (tidak dikirim)</h3>
  <div class="tc" style="max-height:25vh"><table>
    <thead><tr><th>#</th><th>ID_T_TT</th><th>Kelas</th><th>Ruang</th><th>Kapasitas</th><th>Bed</th><th>Masalah</th></tr></thead>
    <tbody>
    <?php foreach ($perlu_review as $i => $r): ?>
      <tr><td><?php echo $i + 1; ?></td><td><?php echo h($r['id_t_tt']); ?></td><td><?php echo h($r['tt']); ?></td>
        <td><?php echo h($r['ruang_master']); ?></td><td><?php echo h($r['jumlah_master']); ?></td><td><?php echo h($r['jml_bed_komponen']); ?></td>
        <td><?php foreach ($r['flags'] as $f): ?><span class="badge fail"><?php echo h($f); ?></span><?php endforeach; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
</body>
</html>
