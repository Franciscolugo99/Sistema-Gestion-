@echo off
setlocal
title FLUS - Configurar Mercado Pago de prueba

set "SCRIPT_DIR=%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%mp_qr_setup.ps1"
set "EXIT_CODE=%ERRORLEVEL%"

echo.
if not "%EXIT_CODE%"=="0" (
  echo La configuracion no se completo. Revisa el mensaje anterior.
) else (
  echo Asistente finalizado.
)
echo.
pause
exit /b %EXIT_CODE%
