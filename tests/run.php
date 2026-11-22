<?php
/** Unit test ringan tanpa dependensi:  php tests/run.php */
require_once __DIR__ . '/../src/helpers.php';

$fail = 0;
function check($name, $actual, $expected) {
    global $fail;
    if ($actual === $expected) { echo "PASS  $name" . PHP_EOL; return; }
    $fail++;
    echo "FAIL  $name" . PHP_EOL . '      expected: ' . json_encode($expected) . PHP_EOL . '      actual  : ' . json_encode($actual) . PHP_EOL;
}

// expand_range
check('range huruf B1-B2',   expand_range('B1-B2'),   ['B1', 'B2']);
check('range huruf C4-C6',   expand_range('C4-C6'),   ['C4', 'C5', 'C6']);
check('range huruf C19-C21', expand_range('C19-C21'), ['C19', 'C20', 'C21']);
check('range angka 1-2',     expand_range('1-2'),     ['1', '2']);
check('range B5-B6',         expand_range('B5-B6'),   ['B5', 'B6']);
check('tanpa range',         expand_range('Lt.1'),    ['Lt.1']);
check('range terbalik',      expand_range('B6-B5'),   ['B6-B5']);
check('range terlalu besar', expand_range('A1-A9999'), ['A1-A9999']);

// compute_occupancy
check('okupansi normal',      compute_occupancy(10, 7),  [10, 7, 3]);
check('terpakai > kapasitas', compute_occupancy(5, 9),   [5, 5, 0]);
check('nilai negatif',        compute_occupancy(-3, -1), [0, 0, 0]);
check('master 0',             compute_occupancy(0, 4),   [0, 0, 0]);

// h()
check('h() escape XSS', h('<script>"x"</script>'), '&lt;script&gt;&quot;x&quot;&lt;/script&gt;');

echo PHP_EOL . ($fail ? "$fail test gagal" : 'Semua test lulus') . PHP_EOL;
exit($fail ? 1 : 0);
