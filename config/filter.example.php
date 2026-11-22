<?php
/**
 * Filter data tempat tidur yang dikirim ke RS Online.
 * Salin menjadi config/filter.php (di-gitignore) lalu sesuaikan dengan SK
 * tempat tidur rumah sakit Anda.
 */
return [
    // Pola LIKE untuk kolom kamar.kd_kamar. Hanya kamar yang cocok yang dikirim.
    // Kosongkan array untuk mengirim semua kamar yang terdaftar di rsonlineTT.
    'whitelist_prefixes' => [
        'A. VIP%',
        'A. A%',
        'B. A%',
        'B. B%',
    ],

    // id_t_tt di sisi Kemenkes yang TIDAK boleh dikirim
    // (mis. sisa data lama yang melebihi kapasitas pada SK).
    'blocked_id_t_tt' => [
        // '12345',
    ],
];
