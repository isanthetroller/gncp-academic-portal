@echo off
title GNCP Selenium CLI Test Pipeline Runner
color 0E
cls

echo ================================================================
echo   GO-ON NATIONAL COLLEGE OF THE PHILIPPINES (GNCP)
echo   Selenium Full End-to-End Test Pipeline (CLI Mode)
echo ================================================================
echo.

python --version >nul 2>&1
if errorlevel 1 (
    color 0C
    echo [ERROR] Python was not found in your system PATH!
    pause
    exit /b 1
)

echo [*] Running full multi-station automated test suite...
echo.

python tests/selenium/test_runner.py

echo.
echo ================================================================
echo   Execution Finished.
echo ================================================================
pause
