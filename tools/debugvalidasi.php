<?php
/**
 * Audit READ-ONLY per kamar (kd_kamar): bandingkan kapasitas master Kemenkes,
 * kapasitas terdaftar, dan jumlah pasien aktif lokal. Tidak mengubah DB dan
 * tidak mengirim apa pun ke RS Online (pengiriman hanya lewat kirimdatakamar.php).
 *
 * CLI : php tools/debugvalidasi.php
 * Web : http://localhost/rsonline/tools/debugvalidasi.php
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_local_access();

$db = db_connect();

$q = $db->query("
    SELECT rt.id_t_tt, rt.tt, rt.kd_kamar,
           COALESCE(k.statusdata, 0) AS statusdata,
           rt.kapasitas_rsonline,
           rt.last_response,
           COALESCE(ki.jml_pasien, 0) AS pasien_aktif
    FROM rsonlineTT rt
    LEFT JOIN kamar k ON k.kd_kamar = rt.kd_kamar
    LEFT JOIN (
        SELECT kd_kamar, COUNT(*) AS jml_pasien
        FROM kamar_inap
        WHERE (tgl_keluar IS NULL OR tgl_keluar = '' OR tgl_keluar = '0000-00-00' OR tgl_keluar = '0000-00-00 00:00:00')
          AND (stts_pulang IS NULL OR stts_pulang IN ('', '-', '+', 'Pindah Kamar', 'Status Belum Lengkap'))
        GROUP BY kd_kamar
    ) ki ON ki.kd_kamar = rt.kd_kamar
    WHERE rt.kd_kamar IS NOT NULL AND rt.kd_kamar <> ''
    ORDER BY rt.kd_kamar, CAST(rt.id_t_tt AS UNSIGNED)
");
if (!$q) { error_log('[rsonline] ' . $db->error); exit('Query audit gagal.' . PHP_EOL); }

$items = [];
while ($row = $q->fetch_assoc()) {
    $lr = json_decode((string)$row['last_response'], true);
    $lr = is_array($lr) ? $lr : [];

    $id_t_tt       = norm($row['id_t_tt']);
    $ruang         = norm(isset($lr['ruang']) ? $lr['ruang'] : $row['kd_kamar']);
    $jumlah_master = isset($lr['jumlah']) ? (int)$lr['jumlah'] : 0;
    $kap           = (int)$row['kapasitas_rsonline'];
    $jumlah        = $kap > 0 ? $kap : 1;
    $statusdata    = (int)$row['statusdata'];
    $pasien        = max(0, (int)$row['pasien_aktif']);
    $terpakai      = min($pasien, $jumlah);

    $flags = [];
    if ($id_t_tt === '')                                   $flags[] = 'ID_T_TT_KOSONG';
    if ($ruang === '')                                     $flags[] = 'RUANG_KOSONG';
    if ($jumlah_master <= 0)                               $flags[] = 'MASTER_KOSONG';
    if ($jumlah_master > 0 && $jumlah !== $jumlah_master)  $flags[] = 'KAPASITAS_BEDA_MASTER';
    if ($pasien > $jumlah)                                 $flags[] = 'OVER_CAPACITY';
    if ($statusdata === 0 && $pasien > 0)                  $flags[] = 'STATUSDATA_0_TAPI_ADA_PASIEN';
    if ($statusdata === 1 && $pasien === 0)                $flags[] = 'STATUSDATA_1_TAPI_PASIEN_0';

    $items[] = ['id_t_tt' => $id_t_tt, 'ruang' => $ruang, 'tt' => norm($row['tt']), 'kd_kamar' => $row['kd_kamar'],
                'statusdata' => $statusdata, 'jumlah_master' => $jumlah_master, 'jumlah' => $jumlah,
                'pasien' => $pasien, 'terpakai' => $terpakai, 'kosong' => $jumlah - $terpakai, 'flags' => $flags];
}
$db->close();

$konflik = array_filter($items, function ($r) { return !empty($r['flags']); });

if (PHP_SAPI === 'cli') {
    echo 'Total=' . count($items) . ' konflik=' . count($konflik) . PHP_EOL;
    foreach ($konflik as $r) {
        echo sprintf('id_t_tt=%s kd_kamar=%s master=%s jumlah=%s pasien=%s flags=%s', $r['id_t_tt'], $r['kd_kamar'],
            $r['jumlah_master'], $r['jumlah'], $r['pasien'], implode(',', $r['flags'])) . PHP_EOL;
    }
    exit(0);
}
?><!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><title>Audit Tempat Tidur</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f9;margin:0;padding:20px}
.card{max-width:1600px;margin:auto;background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h2{margin:0 0 8px;color:#1d4ed8}
.summary{display:flex;gap:12px;margin:12px 0 18px}.box{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:12px 18px}.box strong{display:block;font-size:22px}
table{width:100%;border-collapse:collapse;font-size:12px}th{background:#1d4ed8;color:#fff;padding:10px;text-align:left;position:sticky;top:0}
td{padding:8px 10px;border-bottom:1px solid #e5e7eb}.b{padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;display:inline-block;margin:2px 4px 2px 0}
.ok{background:#dcfce7;color:#166534}.warn{background:#fef3c7;color:#92400e}.fail{background:#fee2e2;color:#991b1b}
</style></head>
<body><div class="card">
<h2>Audit Tempat Tidur (read-only)</h2>
<div class="summary">
  <div class="box"><strong><?php echo count($items); ?></strong>Total kamar</div>
  <div class="box"><strong><?php echo count($konflik); ?></strong>Konflik</div>
</div>
<table><thead><tr><th>#</th><th>id_t_tt</th><th>Ruang</th><th>TT</th><th>kd_kamar</th><th>Status</th><th>Master</th><th>Kapasitas</th><th>Pasien aktif</th><th>Terpakai</th><th>Kosong</th><th>Flags</th></tr></thead><tbody>
<?php foreach ($items as $i => $r): ?>
<tr><td><?php echo $i + 1; ?></td><td><?php echo h($r['id_t_tt']); ?></td><td><?php echo h($r['ruang']); ?></td><td><?php echo h($r['tt']); ?></td>
<td><?php echo h($r['kd_kamar']); ?></td><td><?php echo h($r['statusdata']); ?></td><td><?php echo h($r['jumlah_master']); ?></td><td><?php echo h($r['jumlah']); ?></td>
<td><?php echo h($r['pasien']); ?></td><td><?php echo h($r['terpakai']); ?></td><td><?php echo h($r['kosong']); ?></td>
<td><?php if (!$r['flags']): ?><span class="b ok">BERSIH</span><?php else: foreach ($r['flags'] as $f):
    $cls = in_array($f, ['OVER_CAPACITY', 'ID_T_TT_KOSONG', 'RUANG_KOSONG'], true) ? 'fail' : 'warn'; ?><span class="b <?php echo $cls; ?>"><?php echo h($f); ?></span><?php endforeach; endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div></body></html>
