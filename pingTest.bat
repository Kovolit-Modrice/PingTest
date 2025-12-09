@echo off
echo ================================
echo  PingTest - Execution Fix
echo ================================

REM Docasne povolenie scriptov pre tento proces
powershell -Command "Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass"

REM Spustenie PingTest.ps1 z toho isteho priecinka, kde je tento BAT
powershell -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0PingTest.ps1"

echo.
echo Script finished.
pause
