@echo off
REM Jalankan bridging TT ke RS Online (mode send). Dipanggil oleh Windows Task Scheduler.
REM Ubah PHP_BIN jika PHP tidak berada di lokasi default XAMPP.
setlocal
if "%PHP_BIN%"=="" set "PHP_BIN=C:\xampp\php\php.exe"
set "ROOT=%~dp0.."

"%PHP_BIN%" "%ROOT%\kirimdatakamar.php" send
exit /b %ERRORLEVEL%
