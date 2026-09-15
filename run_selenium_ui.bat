@echo off
title GNCP Selenium Visual Test Suite Runner
color 0A
cls

echo ================================================================
echo   GO-ON NATIONAL COLLEGE OF THE PHILIPPINES (GNCP)
echo   Selenium Automated Testing Suite - Microsoft Edge Launcher
echo ================================================================
echo.

:: 1. Check if Python is installed
python --version >nul 2>&1
if errorlevel 1 (
    color 0C
    echo [ERROR] Python was not found in your system PATH!
    echo Please make sure Python 3.9+ is installed and on PATH.
    echo.
    pause
    exit /b 1
)

:: 2. Terminate any previous stale process on port 8090
powershell -Command "$p = (Get-NetTCPConnection -LocalPort 8090 -ErrorAction SilentlyContinue).OwningProcess; if ($p) { Stop-Process -Id $p -Force -ErrorAction SilentlyContinue }" >nul 2>&1

:: 3. Launch Edge browser after 1.5s delay to ensure server has started
start "" powershell -WindowStyle Hidden -Command "Start-Sleep -Milliseconds 1500; Start-Process msedge 'http://localhost:8090/ui/index.html'"

echo [*] Test Server Starting on Port 8090...
echo [*] Microsoft Edge is launching automatically...
echo.
echo ================================================================
echo   DIRECT URL : http://localhost:8090/ui/index.html
echo   APACHE URL : http://localhost/systemtest/tests/selenium/ui/index.html
echo.
echo   [NOTE] The server will auto-close when you close the tab.
echo ================================================================
echo.

:: 4. Start server in foreground
python tests/selenium/server.py
