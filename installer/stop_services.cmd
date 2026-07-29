@echo off
setlocal
set "PSS=%~dp0flus_services.ps1"
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "Start-Process powershell.exe -Verb RunAs -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-NoExit','-File','%PSS%','-Action','stop')"
