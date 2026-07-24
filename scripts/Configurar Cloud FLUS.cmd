@echo off
setlocal
set "SCRIPT_DIR=%~dp0"
set "APP_ROOT=%SCRIPT_DIR%.."
set "SETUP_SCRIPT=%SCRIPT_DIR%cloud_sync_setup.ps1"

if not exist "%SETUP_SCRIPT%" (
  echo No se encontro el activador Cloud:
  echo %SETUP_SCRIPT%
  echo.
  pause
  exit /b 1
)

powershell.exe -ExecutionPolicy Bypass -NoProfile -File "%SETUP_SCRIPT%" -Root "%APP_ROOT%" -SendInitialStock
echo.
pause
