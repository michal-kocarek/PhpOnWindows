<?php

declare(strict_types=1);

$isLoggingEnabled = (bool)getenv('PHP_IN_BASH_LOGGING');

if ($isLoggingEnabled && false !== strpos(implode(' ', $argv), 'ide-phpinfo.php')) {
    dbg('Testing PHP configuration in IDE? Disable logging first!');
}

function dbg(string $message) {
    global $isLoggingEnabled;

    if (!$isLoggingEnabled) {
        return;
    }

    fwrite(STDERR, '[.] ' . $message . PHP_EOL);
}

function err(string $message) {
    fwrite(STDERR, '[!] PhpInBash: ' . $message . PHP_EOL);
    die(100);
}

function mapPath(string $path) : string {
    $path = strtr($path, ['\\' => '/']);

    $path = preg_replace_callback('@^([a-z]):@i', function($matches) {
        return '/mnt/' . strtolower($matches[1]);
    }, $path);

    return $path;
}

function fget0($stream) {
    $return = fgetc($stream);

    if ($return === false) {
        return null;
    }

    stream_set_blocking($stream, true);

    while(true) {
        $character = fgetc($stream);

        if ($character === "\0") {
            break;
        }

        $return .= $character;
    }

    stream_set_blocking($stream, false);

    return $return;
}

function fgetSize0($stream) {
    $size = fget0($stream);

    if ($size === null) {
        return null;
    }

    if (!ctype_digit($size)) {
        err('Expected size!');
    }

    stream_set_blocking($stream, true);
    $payload = fread($stream, (int)$size);
    $nullByte = fgetc($stream);
    stream_set_blocking($stream, false);

    if ($nullByte !== "\0") {
        err('Expected null byte!');
    }

    return $payload;
}

$windir = getenv('WINDIR');
$knownBashPaths = [
    'System32\\bash.exe',
    'Sysnative\\bash.exe',
];

$bashPath = null;

foreach($knownBashPaths as $knownBashPath) {
    if (file_exists($windir . '\\' . $knownBashPath)) {
        $bashPath = $windir . '\\' . $knownBashPath;
        break;
    }
}

if (!$bashPath) {
    err('bash.exe not found. Are you sure Bash is installed?');
}

$carriedVariables = [
    'XDEBUG_CONFIG',
];

$envValues = implode(' ', array_filter(array_map(function(string $env) {
    $value = getenv($env);

    if ($value === false) {
        return false;
    }

    return $env . '=' . $value;
}, $carriedVariables), function($value) {
    return $value !== false;
}));

dbg('Bash path: ' . $bashPath);

dbg('CWD: ' . getcwd());

dbg('ENV: ' . $envValues);

$arguments = $argv;
array_shift($arguments);

dbg('Input arguments: ' . implode(' ', $arguments));

$xdebugPort = null;
$xdebugHost = null;
$localXdebugHost = '0.0.0.0'; // 127.0.0.1 created problems on Windows. Use firewall to handle the security.
$localXdebugPort = null;

foreach($arguments as &$argument) {
    if (file_exists($argument) && (false !== strpos($argument, '\\') or false !== strpos($argument, '/'))) {
        $argument = mapPath($argument);
    } elseif (preg_match('/^-dxdebug\.remote_port=(\d+)$/', $argument, $matches)) {
        $xdebugPort = $matches[1];
        $localXdebugPort = random_int(50000, 59999);
        $argument = '-dxdebug.remote_port=' . $localXdebugPort;
    } elseif (preg_match('/^-dxdebug\.remote_host=(.+)$/', $argument, $matches)) {
        $xdebugHost = $matches[1];
    }
}
unset($argument);

dbg('Linux arguments: ' . implode(' ', $arguments));

// -dxdebug.remote_log=/mnt/c/Code/UbuntuPhpWindows/xdebugLog.txt
$linuxCmd = ($envValues ? $envValues . ' ' : '') . 'php ' . implode(' ', $arguments);
dbg('Linux command: ' . $linuxCmd);

$cmd = $bashPath . ' -c "' . $linuxCmd . '"';
dbg('Windows command: ' . $cmd);

$socket = null;

if ($xdebugHost && $xdebugPort && $localXdebugPort) {
    dbg('Creating proxy between IDE ' . $xdebugHost . ':' . $xdebugPort . ' and PHP on ' . $localXdebugHost . ':' . $localXdebugPort . ' for IDE ' . $xdebugHost . ':' . $xdebugPort . '.');

    $socket = stream_socket_server('tcp://' . $localXdebugHost . ':' . $localXdebugPort);

    if (!$socket) {
        err('Could not create socket.');
    }

    stream_set_blocking($socket, false);
}

$proc = proc_open($cmd, [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
], $pipes, null, null, ['bypass_shell' => true]);

if (!is_resource($proc)) {
    err('Could not open process!');
}

$exitCode = null;

$connections = [];

while ($exitCode === null) {
    $status = proc_get_status($proc);

    if (!$status) {
        err('Could not get process status!');
    }

    if (!$status['running']) {
        $exitCode = $status['exitcode'];
        break;
    }

    usleep(100);

    if ($socket && false !== ($phpSocket = @stream_socket_accept($socket, 0, $clientSocketName))) {
        $ideSocket = fsockopen($xdebugHost, (int)$xdebugPort);

        stream_set_blocking($phpSocket, false);
        stream_set_blocking($ideSocket, false);

        if (!$ideSocket) {
            err('Could not open IDE socket!');
        }

        $connections[] = [
            'php' => $phpSocket,
            'ide' => $ideSocket,
            'name' => $clientSocketName,
        ];

        dbg('Accepted connection from ' . $clientSocketName);
    }

    foreach($connections as $index => $connection) {
        $closeConnection = false;

        $payload = fgetSize0($connection['php']);
        if ($payload !== null) {
            dbg('Xdebug PHP -> IDE [orig] : ' . $payload);
            $payload = strtr($payload, ['file:///mnt/c/' => 'file:///C:/']);
            dbg('Xdebug PHP -> IDE [fixed]: ' . $payload);
            if (!@fwrite($connection['ide'], strlen($payload) . "\0" . $payload . "\0")) {
                $closeConnection = true;
            }
        }

        if (!$closeConnection) {
            $payload = fget0($connection['ide']);
            if ($payload !== null) {
                dbg('Xdebug IDE -> PHP [orig] : ' . $payload);
                $payload = strtr($payload, ['file://C:/' => 'file:///mnt/c/']);
                dbg('Xdebug IDE -> PHP [fixed]: ' . $payload);
                if (!@fwrite($connection['php'], $payload . "\0")) {
                   $closeConnection = true;
                }
            }
        }

        if ($closeConnection) {
            @fclose($connection['ide']);
            @fclose($connection['php']);
            unset($connections[$index]);
        }
    }
};

proc_close($proc);

die($exitCode);
