<?php
date_default_timezone_set('Asia/Jakarta');
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

$is_cli      = (php_sapi_name() === 'cli');
$db          = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) die('Koneksi DB gagal: ' . $db->connect_error);
$db->set_charset('utf8mb4');

$timestamp   = (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp();
$waktu_kirim = date('Y-m-d H:i:s');

/*
 * Sesuaikan nama tabel dan kolom di config.php dengan struktur DB Anda.
 * Tabel yang digunakan:
 *   TBL_MAPPING  : mapping kamar lokal ke data TT Kemenkes  (rsonlineTT / sejenisnya)
 *   TBL_ROOM     : master kamar + kode bangsal               (kamar / room / bed)
 *   TBL_WARD     : master bangsal / ruangan                  (bangsal / ward / ruangan)
 *   TBL_INPATIENT: data pasien rawat inap aktif              (kamar_inap / inpatient)
 */

// ── Ambil data per id_t_tt + JOIN ke room & ward untuk nama ruang ─────────
$sqlData = "
    SELECT
        m.id_t_tt,
        m.id_tt,
        m.tt,
        m.kode_siranap,
        COUNT(DISTINCT m." . COL_MAP_ROOM . ")                                            AS jumlah,
        COUNT(DISTINCT m." . COL_MAP_ROOM . ")                                            AS jumlah_ruang,
        GROUP_CONCAT(DISTINCT m." . COL_MAP_ROOM . " ORDER BY m." . COL_MAP_ROOM . " SEPARATOR '|') AS kd_kamar_list,
        MAX(w." . COL_WARD_NAME . ")                                                      AS nm_bangsal,
        MIN(m." . COL_MAP_ROOM . ")                                                       AS kd_kamar_min,
        MAX(m." . COL_MAP_ROOM . ")                                                       AS kd_kamar_max
    FROM " . TBL_MAPPING . " m
    LEFT JOIN " . TBL_ROOM . " r ON r." . COL_ROOM_ID . "   = m." . COL_MAP_ROOM . "
    LEFT JOIN " . TBL_WARD . " w ON w." . COL_WARD_ID . "   = r." . COL_ROOM_WARD . "
    WHERE m.id_t_tt IS NOT NULL AND m.id_t_tt != ''
    GROUP BY m.id_t_tt, m.id_tt, m.tt, m.kode_siranap
    ORDER BY m.id_t_tt
";

$rows = $db->query($sqlData)->fetch_all(MYSQLI_ASSOC);

$items = [];
foreach ($rows as $row) {
    $kd_list      = explode('|', $row['kd_kamar_list']);
    $jumlah       = $row['jumlah'];
    $jumlah_ruang = $row['jumlah_ruang'];

    // ── Bangun nama ruang dari nm_bangsal + range kd_kamar ───────────────────
    $nm_bangsal = $row['nm_bangsal'] ?? '-';
    $kd_min     = $row['kd_kamar_min'];
    $kd_max     = $row['kd_kamar_max'];

    $ruang = ($kd_min === $kd_max)
        ? $nm_bangsal . ' ' . $kd_min
        : $nm_bangsal . ' ' . $kd_min . ' - ' . $kd_max;

    // ── Hitung terpakai dari tabel rawat inap ─────────────────────────────────
    $placeholders = implode(',', array_fill(0, count($kd_list), '?'));
    $types        = str_repeat('s', count($kd_list));

    $stmtT = $db->prepare("
        SELECT COUNT(*) AS terpakai
        FROM " . TBL_INPATIENT . "
        WHERE " . COL_INP_ROOM . " IN ($placeholders)
          AND " . COL_INP_DISCHARGE_DATE . " IS NULL
          AND (" . COL_INP_DISCHARGE_STATUS . " IS NULL
               OR " . COL_INP_DISCHARGE_STATUS . " IN ('','-','+','Pindah Kamar','Status Belum Lengkap'))
    ");
    $stmtT->bind_param($types, ...$kd_list);
    $stmtT->execute();
    $terpakai = (int)$stmtT->get_result()->fetch_assoc()['terpakai'];
    $stmtT->close();

    $items[] = [
        'id_t_tt'      => $row['id_t_tt'],
        'id_tt'        => $row['id_tt'],
        'ruang'        => $ruang,
        'tt'           => $row['tt'],
        'jumlah'       => $jumlah,
        'jumlah_ruang' => $jumlah_ruang,
        'terpakai'     => $terpakai,
        'payload'      => json_encode([
            'id_t_tt'             => $row['id_t_tt'],
            'ruang'               => $ruang,
            'jumlah_ruang'        => (string)$jumlah_ruang,
            'jumlah'              => (string)$jumlah,
            'terpakai'            => (string)$terpakai,
            'terpakai_suspek'     => '0',
            'terpakai_konfirmasi' => '0',
            'antrian'             => '0',
            'prepare'             => '0',
            'prepare_plan'        => '0',
            'covid'               => 0,
        ], JSON_UNESCAPED_UNICODE),
    ];
}

$db->close();

// ── cURL Multi: kirim semua paralel dengan method PUT ────────────────────────
$headers = [
    'X-rs-id: '     . RS_ID,
    'X-Timestamp: ' . $timestamp,
    'X-pass: '      . RS_PASS,
    'Content-Type: application/json',
];

$mh      = curl_multi_init();
$handles = [];

foreach ($items as $i => $item) {
    $ch = curl_init(BASE_URL);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $item['payload'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$results = [];
$sukses  = 0;
$gagal   = 0;

foreach ($handles as $i => $ch) {
    $resp_raw  = curl_multi_getcontent($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    $ok = ($http_code >= 200 && $http_code < 300);
    if ($ok) $sukses++; else $gagal++;

    $results[] = array_merge($items[$i], [
        'http_code' => $http_code,
        'curl_err'  => $curl_err,
        'resp'      => $resp_raw,
        'ok'        => $ok,
    ]);
}

curl_multi_close($mh);

// ── Log ke file .txt ─────────────────────────────────────────────────────────
$log_dir = __DIR__ . '/log';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$log_file = $log_dir . '/kirim_tt_' . date('Y-m-d') . '.txt';

$log  = str_repeat('=', 60) . PHP_EOL;
$log .= "KIRIM DATA KAMAR RS ONLINE" . PHP_EOL;
$log .= "Waktu   : " . $waktu_kirim . PHP_EOL;
$log .= "Total   : " . count($results) . " ruang | Sukses: $sukses | Gagal: $gagal" . PHP_EOL;
$log .= str_repeat('=', 60) . PHP_EOL . PHP_EOL;

$log .= "[ SUKSES - $sukses ruang ]" . PHP_EOL;
$log .= str_repeat('-', 60) . PHP_EOL;
foreach ($results as $r) {
    if ($r['ok']) {
        $log .= sprintf(
            "  OK %-45s | id_t_tt: %-8s | Jumlah: %-3s | Terpakai: %s" . PHP_EOL,
            $r['ruang'], $r['id_t_tt'], $r['jumlah'], $r['terpakai']
        );
    }
}

if ($gagal > 0) {
    $log .= PHP_EOL . "[ GAGAL - $gagal ruang ]" . PHP_EOL;
    $log .= str_repeat('-', 60) . PHP_EOL;
    foreach ($results as $r) {
        if (!$r['ok']) {
            $log .= sprintf(
                "  XX %-45s | id_t_tt: %-8s | HTTP: %s | %s" . PHP_EOL,
                $r['ruang'], $r['id_t_tt'], $r['http_code'],
                $r['curl_err'] ?: substr($r['resp'], 0, 80)
            );
        }
    }
}
$log .= PHP_EOL;

file_put_contents($log_file, $log, FILE_APPEND | LOCK_EX);

if ($is_cli) { echo $log; exit(0); }

$tab = $_GET['tab'] ?? 'fail';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kirim Data Kamar RS Online</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#eef2f7;padding:20px}
.card{max-width:1200px;margin:auto;background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
h2{color:#1a56db;margin-bottom:14px;font-size:1.1rem}
.summary{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.box{background:#f8fafc;border-radius:8px;padding:10px 20px;text-align:center;border:1px solid #e2e8f0;min-width:110px}
.box strong{display:block;font-size:1.6rem;font-weight:700}
.box span{font-size:.72rem;color:#6b7280}
.c-ok{color:#166534}.c-fail{color:#991b1b}.c-total{color:#1a56db}
.loginfo{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 14px;font-size:.8rem;color:#166534;margin-bottom:14px}
.tabs{display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:0}
.tab{padding:9px 22px;font-size:.85rem;font-weight:600;background:none;border:none;color:#6b7280;border-bottom:3px solid transparent;margin-bottom:-2px;text-decoration:none}
.tab.af{color:#991b1b;border-bottom-color:#991b1b}
.tab.ao{color:#166534;border-bottom-color:#166534}
table{width:100%;border-collapse:collapse;font-size:.78rem}
th{background:#1a56db;color:#fff;padding:7px 10px;text-align:left}
td{padding:6px 10px;border-bottom:1px solid #f1f5f9;word-break:break-all}
tr:hover td{background:#f8fafc}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700}
.b-ok{background:#dcfce7;color:#166534}.b-fail{background:#fee2e2;color:#991b1b}
.resp{max-width:300px;font-size:.7rem;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
</style>
</head>
<body>
<div class="card">
  <h2>Kirim Data Kamar RS Online [PUT] &mdash; <?php echo $waktu_kirim; ?></h2>
  <div class="summary">
    <div class="box"><strong class="c-total"><?php echo count($results);?></strong><span>Total Ruang</span></div>
    <div class="box"><strong class="c-ok"><?php echo $sukses;?></strong><span>Sukses</span></div>
    <div class="box"><strong class="c-fail"><?php echo $gagal;?></strong><span>Gagal</span></div>
  </div>
  <div class="loginfo">Log: <strong><?php echo $log_file; ?></strong></div>
  <div class="tabs">
    <a href="?tab=fail" class="tab <?php echo $tab==='fail'?'af':'';?>">Gagal (<?php echo $gagal;?>)</a>
    <a href="?tab=ok"   class="tab <?php echo $tab==='ok'?'ao':'';?>">Sukses (<?php echo $sukses;?>)</a>
    <a href="?tab=all"  class="tab <?php echo $tab==='all'?'ao':'';?>">Semua (<?php echo count($results);?>)</a>
  </div>
  <?php
  $display = array_filter($results, function($r) use ($tab) {
      if ($tab==='ok')   return $r['ok'];
      if ($tab==='fail') return !$r['ok'];
      return true;
  });
  ?>
  <?php if (empty($display)): ?>
    <div style="padding:30px;text-align:center;color:#6b7280;font-size:.85rem">
      <?php echo $tab==='fail'?'Semua berhasil dikirim!':'Tidak ada data.'; ?>
    </div>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>#</th><th>id_t_tt</th><th>Ruang (dikirim)</th><th>Kelas</th><th>Jumlah</th><th>Terpakai</th><th>HTTP</th><th>Status</th><th>Response</th></tr>
    </thead>
    <tbody>
    <?php $i=1; foreach($display as $r): ?>
      <tr>
        <td><?php echo $i++;?></td>
        <td><?php echo htmlspecialchars($r['id_t_tt']);?></td>
        <td><?php echo htmlspecialchars($r['ruang']);?></td>
        <td><?php echo htmlspecialchars($r['tt']);?></td>
        <td><?php echo $r['jumlah'];?></td>
        <td><strong><?php echo $r['terpakai'];?></strong></td>
        <td><?php echo $r['http_code'];?></td>
        <td><span class="badge <?php echo $r['ok']?'b-ok':'b-fail';?>"><?php echo $r['ok']?'OK':'GAGAL';?></span></td>
        <td class="resp" title="<?php echo htmlspecialchars($r['resp']);?>"><?php echo htmlspecialchars(substr($r['resp'],0,100));?></td>
      </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
</div>
</body>
</html>
