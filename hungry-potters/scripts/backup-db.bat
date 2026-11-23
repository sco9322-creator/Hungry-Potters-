@echo off
setlocal

if not defined XAMPP_PATH set "XAMPP_PATH=C:\xampp_jaka"
set "BACKUP_DIR=%USERPROFILE%\HungryPottersBackups"
set "LOG_FILE=%BACKUP_DIR%\backup.log"

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"
if errorlevel 1 exit /b 1

for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "STAMP=%%I"
if not defined STAMP (
    echo [%date% %time%] FAIL: Could not create timestamp.>>"%LOG_FILE%"
    exit /b 1
)
set "BACKUP_FILE=%BACKUP_DIR%\hungry_potters_%STAMP%.sql"

"%XAMPP_PATH%\mysql\bin\mysqldump.exe" --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --single-transaction --routines hungry_potters >"%BACKUP_FILE%" 2>>"%LOG_FILE%"
if errorlevel 1 (
    echo [%date% %time%] FAIL: Database backup failed.>>"%LOG_FILE%"
    if exist "%BACKUP_FILE%" del /q "%BACKUP_FILE%"
    exit /b 1
)
if not exist "%BACKUP_FILE%" (
    echo [%date% %time%] FAIL: Dump file was not created.>>"%LOG_FILE%"
    exit /b 1
)
for %%F in ("%BACKUP_FILE%") do set "BACKUP_SIZE=%%~zF"
if %BACKUP_SIZE% LSS 10240 (
    echo [%date% %time%] FAIL: Dump is smaller than 10 KB (%BACKUP_SIZE% bytes).>>"%LOG_FILE%"
    del /q "%BACKUP_FILE%"
    exit /b 1
)

echo [%date% %time%] SUCCESS: %BACKUP_FILE% (%BACKUP_SIZE% bytes).>>"%LOG_FILE%"
forfiles /p "%BACKUP_DIR%" /m "hungry_potters_*.sql" /d -14 /c "cmd /c del /q @path" >>"%LOG_FILE%" 2>&1
exit /b 0
