<?php
/**
 * Alat bantu: bandingkan hasil parsing kd_kamar lokal dengan nama ruang di data
 * Kemenkes, untuk membantu menyusun config/mapping_manual.php. Hanya baca (read-only).
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_local_access();

$db = db_connect();

$expr_kode = "CASE
    WHEN LOCATE('. ', k.kd_kamar) > 0 THEN TRIM(SUBSTRING(k.kd_kamar, LOCATE('. ', k.kd_kamar) + 2))
    WHEN LOCATE('.', k.kd_kamar) > 0 THEN SUBSTRING_INDEX(k.kd_kamar, '.', -1)
    ELSE k.kd_kamar END";
$expr_bangsal = "CASE
    WHEN LOCATE('. ', k.kd_kamar) > 0 THEN SUBSTRING(k.kd_kamar, 1, LOCATE('. ', k.kd_kamar) - 1)
    WHEN LOCATE('.', k.kd_kamar) > 0 THEN SUBSTRING_INDEX(k.kd_kamar, '.', 1)
    ELSE k.kd_kamar END";

$res = $db->query("
    SELECT k.kd_kamar, b.nm_bangsal, $expr_kode AS kode_ruang, CONCAT(b.nm_bangsal, ' ', $expr_kode) AS ruang_lokal
    FROM kamar k LEFT JOIN bangsal b ON b.kd_bangsal = $expr_bangsal
    LIMIT 50
");

$file   = ROOT_DIR . '/data/fasyankes.json';
$sample = [];
if (is_file($file)) {
    $json = json_decode(file_get_contents($file), true);
    if (isset($json['fasyankes'])) {
        $sample = array_slice(array_filter($json['fasyankes'], function ($x) { return !empty($x['ruang']); }), 0, 50);
    }
}
?><!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><title>Debug Ruang</title>
<style>body{font-family:monospace;padding:20px;background:#f5f7fb}table{border-collapse:collapse;width:100%;background:#fff;font-size:.82rem}
th{background:#1a56db;color:#fff;padding:8px 12px;text-align:left}td{padding:7px 12px;border-bottom:1px solid #eee}h3{margin:20px 0 10px;color:#1a56db}</style></head>
<body>
<h3>Ruang lokal dari DB (hasil parsing kd_kamar)</h3>
<table><thead><tr><th>kd_kamar</th><th>nm_bangsal</th><th>kode_ruang</th><th>ruang_lokal</th></tr></thead><tbody>
<?php while ($res && ($row = $res->fetch_assoc())): ?>
<tr><td><?php echo h($row['kd_kamar']); ?></td><td><?php echo h($row['nm_bangsal']); ?></td><td><?php echo h($row['kode_ruang']); ?></td><td><strong><?php echo h($row['ruang_lokal']); ?></strong></td></tr>
<?php endwhile; ?>
</tbody></table>

<h3>Ruang dari data Kemenkes (50 sampel pertama)</h3>
<?php if (!$sample): ?><p>data/fasyankes.json belum ada. Jalankan getdatakamar.php.</p><?php else: ?>
<table><thead><tr><th>id_t_tt</th><th>ruang (Kemenkes)</th></tr></thead><tbody>
<?php foreach ($sample as $s): ?><tr><td><?php echo h($s['id_t_tt']); ?></td><td><strong><?php echo h($s['ruang']); ?></strong></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</body></html>
