<?php
/**
 * Petakan master TT Kemenkes (data/fasyankes.json) ke kamar lokal SIMRS lalu
 * simpan ke tabel rsonlineTT.
 *
 * Default = DRY RUN (tidak menulis ke DB). Tulis ke DB dengan:
 *   CLI : php import_rsonlineTT.php apply
 *   Web : tombol "Terapkan ke Database"
 */
require_once __DIR__ . '/src/bootstrap.php';

$is_cli = (PHP_SAPI === 'cli');
$apply  = false;
if ($is_cli) {
    $apply = (isset($argv[1]) && strtolower($argv[1]) === 'apply');
} else {
    require_local_access();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['apply'] ?? '') === '1') {
        csrf_check();
        $apply = true;
    }
}

$cfg            = load_local_config('mapping_manual');
$mapping_manual = isset($cfg['manual']) ? $cfg['manual'] : [];
$kata_abaikan   = isset($cfg['kata_abaikan']) ? $cfg['kata_abaikan'] : [];

$file = ROOT_DIR . '/data/fasyankes.json';
if (!is_file($file)) exit('data/fasyankes.json tidak ditemukan. Jalankan getdatakamar.php terlebih dahulu.' . PHP_EOL);
$data = json_decode(file_get_contents($file), true);
if (!isset($data['fasyankes']) || !is_array($data['fasyankes'])) exit('Format JSON tidak valid.' . PHP_EOL);

$db = db_connect();

/** Cari kamar lokal berdasarkan nama bangsal + kode bed (hasil parsing kd_kamar). */
function cariKamar($db, $like_bangsal, $like_kode) {
    $sql = "
        SELECT k.kd_kamar,
            CONCAT(b.nm_bangsal, ' ',
                CASE
                    WHEN LOCATE('. ', k.kd_kamar) > 0 THEN TRIM(SUBSTRING(k.kd_kamar, LOCATE('. ', k.kd_kamar) + 2))
                    WHEN LOCATE('.', k.kd_kamar) > 0 THEN SUBSTRING_INDEX(k.kd_kamar, '.', -1)
                    ELSE k.kd_kamar
                END
            ) AS ruang_lokal
        FROM kamar k
        LEFT JOIN bangsal b ON b.kd_bangsal = CASE
                WHEN LOCATE('. ', k.kd_kamar) > 0 THEN SUBSTRING(k.kd_kamar, 1, LOCATE('. ', k.kd_kamar) - 1)
                WHEN LOCATE('.', k.kd_kamar) > 0 THEN SUBSTRING_INDEX(k.kd_kamar, '.', 1)
                ELSE k.kd_kamar
            END
        HAVING UPPER(ruang_lokal) LIKE UPPER(?) AND UPPER(ruang_lokal) LIKE UPPER(?)
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) exit('Prepare gagal: ' . $db->error . PHP_EOL);
    $stmt->bind_param('ss', $like_bangsal, $like_kode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

function upsertKamar($db, $kd_kamar, $item) {
    $id_tt        = $item['id_tt']        ?? null;
    $tt           = $item['tt']           ?? null;
    $kode_siranap = $item['kode_siranap'] ?? null;
    $id_t_tt      = $item['id_t_tt']      ?? null;
    $last_resp    = json_encode($item, JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare("
        INSERT INTO rsonlineTT (kd_kamar, id_tt, tt, kode_siranap, id_t_tt, is_synced, last_response)
        VALUES (?, ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE
            id_tt = VALUES(id_tt), tt = VALUES(tt), kode_siranap = VALUES(kode_siranap),
            id_t_tt = VALUES(id_t_tt), is_synced = 1, last_response = VALUES(last_response), last_update = NOW()
    ");
    if (!$stmt) exit('Prepare upsert gagal: ' . $db->error . PHP_EOL);
    $stmt->bind_param('ssssss', $kd_kamar, $id_tt, $tt, $kode_siranap, $id_t_tt, $last_resp);
    $stmt->execute();
    $stmt->close();
}

$logs_ok = $logs_fail = [];

foreach ($data['fasyankes'] as $item) {
    if (empty($item['ruang'])) continue;
    $ruang_json = trim($item['ruang']);

    // 1) Mapping manual
    $manual_hit = false;
    foreach ($mapping_manual as $key => $kd_list) {
        if (strcasecmp($ruang_json, $key) !== 0) continue;
        $manual_hit = true;
        if (empty($kd_list)) {
            $logs_fail[] = ['ruang_json' => $ruang_json, 'kata_depan' => '(manual kosong)', 'expanded' => '-',
                            'id_tt' => $item['id_tt'] ?? '-', 'tt' => $item['tt'] ?? '-', 'id_t_tt' => $item['id_t_tt'] ?? '-'];
            break;
        }
        foreach ($kd_list as $kd_kamar) {
            if ($apply) upsertKamar($db, $kd_kamar, $item);
            $logs_ok[] = ['ruang_json' => $ruang_json, 'kode_single' => '(manual)', 'kd_kamar' => $kd_kamar,
                          'ruang_lokal' => $kd_kamar, 'id_t_tt' => $item['id_t_tt'] ?? '-'];
        }
        break;
    }
    if ($manual_hit) continue;

    // 2) Parsing otomatis: kata pertama = nama bangsal, kata terakhir (tanpa kata abaikan) = kode bed
    $parts       = preg_split('/\s+/', $ruang_json);
    $kata_depan  = $parts[0];
    $kata_bersih = array_values(array_filter($parts, function ($p) use ($kata_abaikan) {
        return !in_array($p, $kata_abaikan, false);
    }));
    $kode_akhir   = end($kata_bersih);
    $expanded     = expand_range($kode_akhir);
    $like_bangsal = '%' . $kata_depan . '%';
    $matched_any  = false;

    foreach ($expanded as $kode_single) {
        $lokal = cariKamar($db, $like_bangsal, '% ' . $kode_single);          // akhiran persis " B5"
        if (!$lokal) $lokal = cariKamar($db, $like_bangsal, '%' . $kode_single . '%'); // fallback
        if (!$lokal) continue;

        if ($apply) upsertKamar($db, $lokal['kd_kamar'], $item);
        $matched_any = true;
        $logs_ok[] = ['ruang_json' => $ruang_json, 'kode_single' => $kode_single, 'kd_kamar' => $lokal['kd_kamar'],
                      'ruang_lokal' => $lokal['ruang_lokal'], 'id_t_tt' => $item['id_t_tt'] ?? '-'];
    }

    if (!$matched_any) {
        $logs_fail[] = ['ruang_json' => $ruang_json, 'kata_depan' => $kata_depan, 'expanded' => implode(', ', $expanded),
                        'id_tt' => $item['id_tt'] ?? '-', 'tt' => $item['tt'] ?? '-', 'id_t_tt' => $item['id_t_tt'] ?? '-'];
    }
}
$db->close();

if ($is_cli) {
    echo ($apply ? 'APPLY' : 'DRY RUN') . ': cocok=' . count($logs_ok) . ' tidak_cocok=' . count($logs_fail) . PHP_EOL;
    foreach ($logs_fail as $f) echo '  - tidak cocok: ' . $f['ruang_json'] . ' (id_t_tt=' . $f['id_t_tt'] . ')' . PHP_EOL;
    exit(0);
}

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'ok') ? 'ok' : 'fail';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Import rsonlineTT</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#eef2f7;padding:20px}
.card{max-width:1200px;margin:auto;background:#fff;padding:20px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
h2{color:#1a56db;margin-bottom:14px;font-size:1.1rem}
.banner{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:.85rem}
.b-dry{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}.b-apply{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.summary{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.box{background:#f8fafc;border-radius:8px;padding:10px 20px;text-align:center;border:1px solid #e2e8f0;min-width:110px}
.box strong{display:block;font-size:1.6rem}.box span{font-size:.72rem;color:#6b7280}
.c-ok{color:#166534}.c-fail{color:#991b1b}.c-total{color:#1a56db}
.tabs{display:flex;border-bottom:2px solid #e2e8f0}
.tab{padding:9px 22px;font-size:.85rem;font-weight:600;color:#6b7280;border-bottom:3px solid transparent;margin-bottom:-2px;text-decoration:none}
.tab.af{color:#991b1b;border-bottom-color:#991b1b}.tab.ao{color:#166534;border-bottom-color:#166534}
table{width:100%;border-collapse:collapse;font-size:.8rem}
th{background:#1a56db;color:#fff;padding:8px 12px;text-align:left}
td{padding:7px 12px;border-bottom:1px solid #f1f5f9;word-break:break-word}
.info{padding:30px;text-align:center;color:#6b7280;font-size:.85rem}
.manual{background:#fef9c3;font-size:.72rem;padding:2px 6px;border-radius:4px;color:#854d0e}
.btn{padding:8px 16px;background:#1a56db;color:#fff;border:0;border-radius:6px;cursor:pointer;font-weight:600;margin-top:8px}
</style>
</head>
<body>
<div class="card">
  <h2>Import Data RS Online &rarr; rsonlineTT</h2>

  <?php if ($apply): ?>
    <div class="banner b-apply">&#10003; Perubahan sudah ditulis ke database.</div>
  <?php else: ?>
    <div class="banner b-dry"><strong>DRY RUN</strong> &mdash; belum ada data yang ditulis ke database. Periksa hasil di bawah, lalu terapkan.
      <form method="POST" onsubmit="return confirm('Tulis hasil pemetaan ke tabel rsonlineTT?')">
        <input type="hidden" name="csrf" value="<?php echo h(csrf_token()); ?>">
        <input type="hidden" name="apply" value="1">
        <button class="btn" type="submit">Terapkan ke Database</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="summary">
    <div class="box"><strong class="c-total"><?php echo count($logs_ok) + count($logs_fail); ?></strong><span>Total hasil</span></div>
    <div class="box"><strong class="c-ok"><?php echo count($logs_ok); ?></strong><span>Record cocok</span></div>
    <div class="box"><strong class="c-fail"><?php echo count($logs_fail); ?></strong><span>Tidak cocok</span></div>
  </div>
  <div class="tabs">
    <a href="?tab=fail" class="tab <?php echo $tab === 'fail' ? 'af' : ''; ?>">Tidak cocok (<?php echo count($logs_fail); ?>)</a>
    <a href="?tab=ok" class="tab <?php echo $tab === 'ok' ? 'ao' : ''; ?>">Cocok (<?php echo count($logs_ok); ?>)</a>
  </div>

  <?php if ($tab === 'fail'): ?>
    <?php if (!$logs_fail): ?><div class="info">&#10003; Semua ruang berhasil dicocokkan.</div>
    <?php else: ?>
    <table><thead><tr><th>#</th><th>Ruang JSON</th><th>Kata depan</th><th>Expand</th><th>id_tt</th><th>tt</th><th>id_t_tt</th></tr></thead><tbody>
    <?php foreach ($logs_fail as $i => $l): ?>
      <tr><td><?php echo $i + 1; ?></td><td><?php echo h($l['ruang_json']); ?></td><td><?php echo h($l['kata_depan']); ?></td>
        <td><?php echo h($l['expanded']); ?></td><td><?php echo h($l['id_tt']); ?></td><td><?php echo h($l['tt']); ?></td><td><?php echo h($l['id_t_tt']); ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>
  <?php else: ?>
    <?php if (!$logs_ok): ?><div class="info">Belum ada data.</div>
    <?php else: ?>
    <table><thead><tr><th>#</th><th>Ruang JSON</th><th>Kode</th><th>kd_kamar</th><th>Ruang lokal</th><th>id_t_tt</th></tr></thead><tbody>
    <?php foreach ($logs_ok as $i => $l): ?>
      <tr><td><?php echo $i + 1; ?></td><td><?php echo h($l['ruang_json']); ?></td>
        <td><?php echo $l['kode_single'] === '(manual)' ? '<span class="manual">manual</span>' : h($l['kode_single']); ?></td>
        <td><?php echo h($l['kd_kamar']); ?></td><td><?php echo h($l['ruang_lokal']); ?></td><td><?php echo h($l['id_t_tt']); ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
