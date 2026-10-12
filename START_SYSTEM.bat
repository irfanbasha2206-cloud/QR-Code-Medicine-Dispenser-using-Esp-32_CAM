@echo off
setlocal enabledelayedexpansion
title AegisRx Smart Medicine Dispenser
echo ============================================================
echo   AegisRx - Smart Prescription QR Scanner ^& IoT Dispenser
echo ============================================================
echo.

:: 1. Auto-detect Python executable across different environments
set "PY_CMD="

:: 1. Check current user's Local AppData Python installation (prefer newer 64-bit: 3.13, 3.12, 3.11, 3.10)
for %%v in (313 312 311 310 39) do (
    if not defined PY_CMD if exist "%LOCALAPPDATA%\Programs\Python\Python%%v\python.exe" (
        set "PY_CMD=%LOCALAPPDATA%\Programs\Python\Python%%v\python.exe"
    )
)

:: 2. Check other Local AppData Python folders (excluding -32 bit stubs)
if not defined PY_CMD (
    for /d %%d in ("%LOCALAPPDATA%\Programs\Python\Python3*") do (
        echo "%%d" | findstr /i "\-32" >nul
        if errorlevel 1 (
            if not defined PY_CMD if exist "%%d\python.exe" set "PY_CMD=%%d\python.exe"
        )
    )
)

:: 2. Check Program Files standard installation
if not defined PY_CMD (
    for /d %%d in ("%ProgramFiles%\Python3*", "%ProgramFiles(x86)%\Python3*") do (
        if exist "%%d\python.exe" set "PY_CMD=%%d\python.exe"
    )
)

:: 3. Check system python in PATH (skipping WindowsApps stub)
if not defined PY_CMD (
    for /f "delims=" %%i in ('where python 2^>nul') do (
        echo "%%i" | findstr /i "WindowsApps" >nul
        if errorlevel 1 (
            if not defined PY_CMD if exist "%%i" set "PY_CMD=%%i"
        )
    )
)

:: 4. Check standard root C:\Python3*
if not defined PY_CMD (
    for /d %%d in ("C:\Python3*") do (
        if exist "%%d\python.exe" set "PY_CMD=%%d\python.exe"
    )
)

:: 5. Check Python Windows Launcher (py.exe)
if not defined PY_CMD (
    where py >nul 2>&1
    if !errorlevel! equ 0 set "PY_CMD=py"
)

:: 6. Check active conda environment
if not defined PY_CMD if defined CONDA_PREFIX (
    if exist "%CONDA_PREFIX%\python.exe" set "PY_CMD=%CONDA_PREFIX%\python.exe"
)

:: Check Anaconda/Miniconda in user profile
if not defined PY_CMD (
    for /d %%d in ("%USERPROFILE%\anaconda3", "%USERPROFILE%\miniconda3", "%LOCALAPPDATA%\anaconda3") do (
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
"!PY_CMD!" -c "import flask, cv2, numpy, requests, sklearn, joblib" >nul 2>&1
if !errorlevel! neq 0 (
    echo.
    echo [Setup] First-time launch detected! Installing required dependencies...
    echo [Setup] Installing: Flask, OpenCV, Pyzbar, NumPy, Scikit-Learn, Joblib...
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
