@echo off
title GNCP Auth & Password Recovery Quick Test Runner
color 0B
cls

echo ================================================================
echo   GO-ON NATIONAL COLLEGE OF THE PHILIPPINES (GNCP)
echo   Fast Authentication, Profile & Password Recovery Test Suite
echo ================================================================
echo.

python tests/test_auth_logout_flows.py
echo.
python tests/test_profile_and_logout_e2e.py
echo.
python tests/test_student_forgot_password.py

echo.
echo ================================================================
echo   All Quick Tests Completed!
echo ================================================================
pause
