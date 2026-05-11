@echo off
REM ============================================================
REM  Kirim Data Tempat Tidur ke RS Online Kemenkes
REM  Jalankan via Windows Task Scheduler
REM ============================================================

REM Sesuaikan path PHP dan path script
SET PHP_EXE=C:\xampp\php\php.exe
SET SCRIPT=C:\rsonline\kirimdatakamar.php

%PHP_EXE% %SCRIPT%
