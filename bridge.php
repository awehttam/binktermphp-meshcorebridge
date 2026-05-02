#!/usr/bin/env php
<?php

/**
 * binkterm-php MeshCore Bridge
 *
 * Connects to a MeshCore node over USB serial using the binary companion
 * protocol and forwards commands to the binkterm-php packet BBS gateway API.
 *
 * Usage:
 *   php bridge.php [--debug] [--trace] [--log-level=DEBUG] [path/to/bridge.json]
 *
 * Configuration is read from bridge.json (or the path given as argument).
 * Copy bridge.json.example to bridge.json and edit it before running.
 *
 * Interactive console commands while running, when non-blocking STDIN is
 * available. These are disabled for Windows TTY sessions and when daemonized:
 *   advert / a   Send zero-hop advert immediately
 *   flood / af   Send flood advert immediately
 *   help / ?     Show commands
 *   quit / exit  Stop the bridge
 *
 * Options:
 *   --debug            Log bridge-relevant operations (DMs, BBS calls, replies).
 *   --trace            Log all decoded over-the-air packets.
 *   --log-level=DEBUG  Alias for --debug.
 *   --daemon           Daemonize (Linux/Unix only; requires pcntl + posix).
 *   --pid-file=PATH    Write daemon PID to PATH (only with --daemon).
 *   --log-file=PATH    Append output to PATH when daemonized (default: null device).
 */

require_once __DIR__ . '/vendor/autoload.php';

use MeshCoreBridge\MeshCoreBridge;

$debug = false;
$trace = false;
$daemon = false;
$pidFile = null;
$logFile = null;
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo <<<'HELP'
Usage: bridge.php [options] [config]

  config               Path to JSON config file (default: bridge.json)

Options:
  --debug              Log bridge-relevant operations
  --trace              Log all decoded over-the-air packets
  --log-level=DEBUG    Alias for --debug
  --daemon             Daemonize (Linux/Unix only; requires pcntl + posix extensions)
  --pid-file=PATH      Write PID to PATH when daemonized
  --log-file=PATH      Append output to PATH when daemonized (default: null device)
  --help, -h           Show this help

Interactive commands while running, when non-blocking STDIN is available
(disabled for Windows TTY sessions and when daemonized):
  advert / a           Send zero-hop advert immediately
  flood / af           Send flood advert immediately
  help / ?             Show console commands
  quit / exit          Stop the bridge

HELP;
        exit(0);
    } elseif ($arg === '--debug' || $arg === '--log-level=DEBUG') {
        $debug = true;
    } elseif ($arg === '--trace') {
        $trace = true;
    } elseif ($arg === '--daemon') {
        $daemon = true;
    } elseif (str_starts_with($arg, '--pid-file=')) {
        $pidFile = substr($arg, 11);
    } elseif (str_starts_with($arg, '--log-file=')) {
        $logFile = substr($arg, 11);
    } else {
        $positional[] = $arg;
    }
}

$configPath = $positional[0] ?? __DIR__ . '/bridge.json';

if (!file_exists($configPath)) {
    fprintf(STDERR, "Config file not found: %s\n", $configPath);
    fprintf(STDERR, "Copy bridge.json.example to bridge.json and edit it.\n");
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
if (!is_array($config)) {
    fprintf(STDERR, "Invalid JSON in config file: %s\n", $configPath);
    exit(1);
}

$config['debug'] = $debug;
$config['trace'] = $trace;

$required = ['bbs_url', 'api_key', 'serial_port'];
foreach ($required as $key) {
    if (empty($config[$key])) {
        fprintf(STDERR, "Missing required config key: %s\n", $key);
        exit(1);
    }
}

if ($daemon) {
    if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
        fprintf(STDERR, "Error: --daemon requires the pcntl and posix extensions (Linux/Unix only)\n");
        exit(1);
    }

    flush();

    // First fork: parent exits so the child is not a process group leader.
    $pid = pcntl_fork();
    if ($pid === -1) {
        fprintf(STDERR, "Error: pcntl_fork() failed\n");
        exit(1);
    }
    if ($pid > 0) {
        exit(0);
    }

    // Create a new session to detach from the controlling terminal.
    if (posix_setsid() === -1) {
        exit(1);
    }

    // Second fork: ensures the daemon cannot re-acquire a controlling terminal.
    $pid = pcntl_fork();
    if ($pid === -1) {
        exit(1);
    }
    if ($pid > 0) {
        exit(0);
    }

    // Redirect the standard file descriptors. After fclose(), the next fopen()
    // call gets the lowest available file descriptor (0, 1, 2 in order), so
    // echo/flush and OS-level writes land in the right place.
    $outDest = $logFile ?? (PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null');
    fclose(STDIN);
    $daemonStdin  = fopen('/dev/null', 'r');  // fd 0
    fclose(STDOUT);
    $daemonStdout = fopen($outDest, 'a');     // fd 1
    fclose(STDERR);
    $daemonStderr = fopen($outDest, 'a');     // fd 2

    if ($pidFile !== null) {
        file_put_contents($pidFile, getmypid() . "\n");
        register_shutdown_function(function () use ($pidFile) {
            @unlink($pidFile);
        });
    }
}

try {
    $bridge = new MeshCoreBridge($config);
    if ($debug) {
        echo "[" . date('Y-m-d H:i:s') . "] Debug mode enabled\n";
    }
    if ($trace) {
        echo "[" . date('Y-m-d H:i:s') . "] Trace mode enabled\n";
    }
    $bridge->run();
} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Fatal: " . $e->getMessage() . "\n";
    flush();
    exit(1);
}
