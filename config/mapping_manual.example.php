<?php
/**
 * Aturan pencocokan ruang Kemenkes -> kamar lokal SIMRS.
 * Salin menjadi config/mapping_manual.php (di-gitignore) lalu sesuaikan.
 */
return [
    // Nama ruang Kemenkes yang tidak bisa dicocokkan otomatis.
    // Key   = nama ruang persis seperti di data Kemenkes (tidak peka huruf besar/kecil)
    // Value = daftar kd_kamar lokal (tabel `kamar`) yang diwakilinya
    'manual' => [
        'MELATI B5-B6' => ['M. B5', 'M. B6'],
        'NICU Lt.1'    => ['PN. A.BED 1', 'PN. A.BED 2'],
    ],

    // Kata yang diabaikan saat pencocokan otomatis (hanya ada di nama versi Kemenkes)
    'kata_abaikan' => ['Iso', 'Obs', 'VVIP'],
];
