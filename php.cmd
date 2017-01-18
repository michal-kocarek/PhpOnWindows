@echo off

IF NOT EXIST "%~dp0.php-path" (
    echo Please create file '%~dp0.php-path' containing absolute path to the Windows PHP executable. 1>&2
    exit /b 100
)

:: Path to PHP >= 7.0 inside Windows
set /P LOCAL_PHP_PATH= < "%~dp0.php-path"

:: Enable debug messages? (default: <none>)
:: - 1 to enable debug messages while running PHP script.
::
:: !! Leave logging disabled when testing PHP configuration inside JetBrains IDE. Logging interferes with automatic configuration check !!
set PHP_IN_BASH_LOGGING=

IF "%LOCAL_PHP_PATH%" == "" (
    echo Please create file '%~dp0.php-path' containing absolute path to the Windows PHP executable. 1>&2
    exit /b 100
)

IF NOT EXIST "%LOCAL_PHP_PATH%" (
    echo Path to PHP executable in file '%~dp0.php-path' is not valid. 1>&2
    exit /b 100
)

"%LOCAL_PHP_PATH%" -f %~dp0php-bridge.php -- %*

exit /b %ERRORLEVEL%
