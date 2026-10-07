@echo off
setlocal enabledelayedexpansion
title AegisRx Smart Medicine Dispenser
echo ============================================================
echo   AegisRx - Smart Prescription QR Scanner ^& IoT Dispenser
echo ============================================================
echo.

:: 1. Auto-detect Python executable across different environments
set "PY_CMD="

:: Check developer's Anaconda env if available
if exist "C:\Users\OVIYA\anaconda3\envs\myenv\python.exe" (
    set "PY_CMD=C:\Users\OVIYA\anaconda3\envs\myenv\python.exe"
)

:: Check UserProfile Anaconda/Miniconda
if not defined PY_CMD if exist "%USERPROFILE%\anaconda3\envs\myenv\python.exe" (
    set "PY_CMD=%USERPROFILE%\anaconda3\envs\myenv\python.exe"
)

:: Check active conda environment
if not defined PY_CMD if defined CONDA_PREFIX (
    set "PY_CMD=%CONDA_PREFIX%\python.exe"
)

:: Check system python command
if not defined PY_CMD (
    python --version >nul 2>&1
    if !errorlevel! equ 0 set "PY_CMD=python"
)

:: Check Python Windows Launcher (py)
if not defined PY_CMD (
    py --version >nul 2>&1
    if !errorlevel! equ 0 set "PY_CMD=py"
)

:: Check standard Windows Python installation directory
if not defined PY_CMD (
    for /d %%d in ("%LOCALAPPDATA%\Programs\Python\Python3*") do (
        if exist "%%d\python.exe" set "PY_CMD=%%d\python.exe"
    )
)

:: If still not found, alert user with helpful instructions
if not defined PY_CMD (
    echo [ERROR] Python was not found on this computer!
    echo.
    echo Please install Python 3.9 or higher from:
    echo https://www.python.org/downloads/
    echo.
    echo IMPORTANT: During installation, make sure to check
    echo            "Add python.exe to PATH"
    echo.
    pause
    exit /b 1
)

echo [Environment] Python Interpreter: !PY_CMD!

:: 2. Auto-verify required Python libraries (Auto-installs for client on first run)
"!PY_CMD!" -c "import flask, cv2, numpy, requests" >nul 2>&1
if !errorlevel! neq 0 (
    echo.
    echo [Setup] First-time launch detected! Installing required dependencies...
    echo [Setup] Installing: Flask, OpenCV, Pyzbar, NumPy...
    "!PY_CMD!" -m pip install -r "%~dp0requirements.txt"
    if !errorlevel! neq 0 (
        echo.
        echo [ERROR] Failed to install dependencies. Check your internet connection.
        pause
        exit /b 1
    )
    echo [Setup] Dependencies installed successfully!
    echo.
)

echo [Web Engine] Starting Clinical Station on Port 5000...
echo.

:: 3. Launch AegisRx Application (works from any folder / USB drive)
"!PY_CMD!" "%~dp0app.py"

echo.
echo Application closed.
pause
