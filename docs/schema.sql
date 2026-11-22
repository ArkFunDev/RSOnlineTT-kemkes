-- Tabel pendamping yang dibuat oleh integrasi ini di database SIMRS Khanza.
-- CATATAN: diturunkan dari query pada source code; sesuaikan tipe/panjang kolom
-- dengan skema Anda (kd_kamar mengikuti tabel `kamar` milik SIMRS).
-- Tabel SIMRS yang dibaca (read-only): kamar, kamar_inap, bangsal.

CREATE TABLE IF NOT EXISTS rsonlineTT (
    kd_kamar            VARCHAR(15)  NOT NULL,           -- relasi ke kamar.kd_kamar
    id_tt               VARCHAR(20)  NULL,               -- ID jenis/kelas TT (master Kemenkes)
    tt                  VARCHAR(100) NULL,               -- nama kelas TT
    kode_siranap        VARCHAR(50)  NULL,
    id_t_tt             VARCHAR(20)  NULL,               -- ID ruang di sisi Kemenkes
    is_synced           TINYINT(1)   NOT NULL DEFAULT 0,
    last_response       LONGTEXT     NULL,               -- JSON: data master / state terakhir terkirim
    kapasitas_rsonline  INT          NULL,               -- dipakai oleh tools/debugvalidasi.php
    last_update         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kd_kamar),                              -- wajib unik: dipakai ON DUPLICATE KEY UPDATE
    KEY idx_id_t_tt (id_t_tt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
