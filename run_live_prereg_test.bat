@echo off
title GNCP — Live Browser Pre-Registration Automated Test
color 0A
cls

echo ================================================================
echo   GO-ON NATIONAL COLLEGE OF THE PHILIPPINES (GNCP)
echo   Live Browser UI Test — Online Pre-Registration Automation
echo ================================================================
echo.
echo [*] Checking Python environment...

python --version >nul 2>&1
if errorlevel 1 (
    color 0C
    echo [ERROR] Python was not found in your system PATH!
    echo Please make sure Python 3.9+ is installed.
    echo.
    pause
    exit /b 1
)

echo [*] Launching automated browser simulation...
echo [*] Chrome window will open visibly on your screen.
echo.

python tests/selenium/test_live_preregistration.py --speed 1.0 --hold 10

echo.
echo ================================================================
echo   Test completed. You can re-run this script anytime.
echo ================================================================
echo.
pause
