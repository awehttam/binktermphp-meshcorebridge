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
 * Interactive console commands while running:
 *   advert / a   Send zero-hop advert immediately
 *   flood / af   Send flood advert immediately
 *   help / ?     Show commands
 *   quit / exit  Stop the bridge
 *
 * Options:
 *   --debug            Log bridge-relevant operations (DMs, BBS calls, replies).
 *   --trace            Log all decoded over-the-air packets.
 *   --log-level=DEBUG  Alias for --debug.
 */

require_once __DIR__ . '/vendor/autoload.php';

use MeshCoreBridge\MeshCoreBridge;

$debug = false;
$trace = false;
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
  --help, -h           Show this help

Interactive commands while running:
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

try {
    $bridge = new MeshCoreBridge($config);
    if ($debug) {
        fprintf(STDOUT, "[%s] Debug mode enabled\n", date('Y-m-d H:i:s'));
    }
    if ($trace) {
        fprintf(STDOUT, "[%s] Trace mode enabled\n", date('Y-m-d H:i:s'));
    }
    $bridge->run();
} catch (\Exception $e) {
    fprintf(STDERR, "Fatal: %s\n", $e->getMessage());
    exit(1);
}
