<?php

namespace MeshCoreBridge;

/**
 * MeshCore-to-BBS bridge (companion USB protocol).
 *
 * Connects to a MeshCore node over USB serial using the binary companion
 * protocol, watches for incoming direct messages, forwards each command to
 * the binkterm-php packet BBS gateway API, and relays the response back.
 *
 * Startup handshake:
 *   1. Send DeviceQuery → wait for DeviceInfo
 *   2. Send AppStart    → wait for SelfInfo
 *
 * Message flow (radio → BBS):
 *   MsgWaiting (0x83) push → send SyncNextMessage in a loop until
 *   NoMoreMessages; each ContactMsgRecv is forwarded to the BBS API
 *   and the plain-text response is sent back via SendTxtMsg.
 *
 * Outbound flow (BBS → radio):
 *   The bridge polls GET /api/packetbbs/pending every poll_interval_seconds
 *   and transmits any queued messages with SendTxtMsg.
 *
 * Node IDs in the BBS API are the 12-character lowercase hex encoding of
 * the 6-byte public-key prefix returned in ContactMsgRecv packets.
 */
class MeshCoreBridge
{
    private SerialPort $serial;
    private BbsApiClient $api;
    private array $config;

    private bool $running           = false;
    private int  $lastPollTime      = 0;
    private int  $lastZeroHopAdvert = 0;
    private int  $lastFloodAdvert   = 0;
    private bool $startupAdvertSent = false;
    private float $lastMessageProbeTime = 0.0;

    /** Whether we should send the next SyncNextMessage command. */
    private bool $syncPending    = false;
    /** Whether we have already sent SyncNextMessage and are awaiting a reply. */
    private bool $waitingForSync = false;
    private float $lastSyncRequestTime = 0.0;

    /** pub_key_hex => unix timestamp of last command, for rate limiting. */
    private array $lastCommandTime = [];
    /** @var string[] node IDs configured for outbound pending-message polling. */
    private array $pollNodeIds = [];
    /** @var array<int,array<string,mixed>> adverts received before bridge auth is ready. */
    private array $queuedAdvertPackets = [];

    /** @var resource|null */
    private $stdin = null;
    private bool $stdinInteractive = false;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->serial = new SerialPort(
            $config['serial_port'],
            (int)($config['baud_rate'] ?? 115200)
        );
        $this->api = new BbsApiClient(
            $config['bbs_url'],
            $config['api_key'],
            (int)($config['http_timeout'] ?? 15)
        );
        $this->pollNodeIds = $this->normalizePollNodeIds($config['poll_node_ids'] ?? []);

        if (defined('STDIN')) {
            $this->stdin = STDIN;
            $meta = @stream_get_meta_data($this->stdin);
            if (is_array($meta)) {
                $isTty = function_exists('stream_isatty') && @stream_isatty($this->stdin);
                if (!(PHP_OS_FAMILY === 'Windows' && $isTty)) {
                    @stream_set_blocking($this->stdin, false);
                    $this->stdinInteractive = true;
                }
            }
        }
    }

    /**
     * Open the serial port, perform the companion handshake, then run the
     * event loop until interrupted. Reconnects automatically if the port
     * closes or becomes unavailable.
     */
    public function run(): void
    {
        $reconnectDelay = (int)($this->config['reconnect_delay_seconds'] ?? 5);

        $this->running = true;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT,  function () { $this->running = false; });
            pcntl_signal(SIGTERM, function () { $this->running = false; });
        }

        while ($this->running) {
            try {
                $this->serial->open();
                $this->startupAdvertSent = false;
                $this->log(sprintf('Connected on %s', $this->serial->getDevice()));
                $bootDelay = (float)($this->config['post_open_delay_seconds'] ?? 0.0);
                if ($bootDelay > 0.0) {
                    $this->log(sprintf('Waiting %.1f s for device boot...', $bootDelay));
                    usleep((int)($bootDelay * 1_000_000));
                    $this->log('Boot delay complete, starting handshake.');
                }
                $this->handshake();
                $this->verifyBbs();
                $this->mainLoop();
            } catch (\RuntimeException $e) {
                $this->log('Serial error: ' . $e->getMessage());
                $this->serial->close();
                if (!$this->running) {
                    break;
                }
                $this->log(sprintf('Reconnecting in %d s...', $reconnectDelay));
                $this->sleepInterruptible($reconnectDelay);
                $this->syncPending    = false;
                $this->waitingForSync = false;
            }
        }

        $this->serial->close();
        $this->log('Bridge stopped.');
    }

    private function mainLoop(): void
    {
        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $this->probeMessageQueue();
            $this->checkSyncTimeout();

            // Drain pending messages from the radio.
            if ($this->syncPending && !$this->waitingForSync) {
                $this->serial->writeFrame(Packet::syncNextMessage());
                $this->waitingForSync = true;
                $this->lastSyncRequestTime = microtime(true);
                $this->syncPending    = false;
            }

            $payload = $this->serial->readFrame();
            if ($payload !== null) {
                $this->handleFrame($payload);
            }

            $this->pollConsoleInput();
            $this->pollPending();
            $this->sendAdverts();

            usleep(20000); // 20 ms
        }
    }

    private function probeMessageQueue(): void
    {
        if ($this->waitingForSync || $this->syncPending) {
            return;
        }

        $interval = (float)($this->config['message_probe_interval_seconds'] ?? 2.0);
        if ($interval <= 0.0) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastMessageProbeTime) < $interval) {
            return;
        }

        $this->lastMessageProbeTime = $now;
        $this->syncPending = true;
    }

    private function checkSyncTimeout(): void
    {
        if (!$this->waitingForSync) {
            return;
        }

        $timeout = (float)($this->config['message_sync_timeout_seconds'] ?? 1.0);
        if ($timeout <= 0.0) {
            return;
        }

        if ((microtime(true) - $this->lastSyncRequestTime) < $timeout) {
            return;
        }

        $this->waitingForSync = false;
    }

    private function sleepInterruptible(int $seconds): void
    {
        $deadline = time() + $seconds;
        while ($this->running && time() < $deadline) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            usleep(100000); // 100 ms
        }
    }

    // -------------------------------------------------------------------------
    // Handshake
    // -------------------------------------------------------------------------

    private function handshake(): void
    {
        $timeout = (float)($this->config['handshake_timeout_seconds'] ?? 30.0);

        $this->serial->writeFrame(Packet::deviceQuery());
        $devInfo = $this->waitForFrame(Packet::RESP_DEVICE_INFO, $timeout);
        if ($devInfo !== null) {
            $this->logDeviceInfo($devInfo);
        } else {
            $this->log('Warning: no DeviceInfo within handshake timeout; will log if it arrives later.');
        }

        // Send AppStart regardless; SelfInfo (and any late DeviceInfo) will be
        // handled by the main loop whenever the device sends them.
        $this->serial->writeFrame(Packet::appStart());
        $selfInfo = $this->waitForFrame(Packet::RESP_SELF_INFO, $timeout);
        if ($selfInfo !== null) {
            $this->logSelfInfo($selfInfo);
            $this->flushQueuedAdvertPackets();
            $this->sendStartupAdvert();
        } else {
            $this->log('Warning: no SelfInfo within handshake timeout; will log if it arrives later.');
        }
    }

    /**
     * Block (polling non-blocking reads) until a frame with the given response
     * code arrives or the timeout elapses. Non-matching frames are dropped
     * since this is only called at startup before any meaningful traffic.
     *
     * @return array<string,mixed>|null
     */
    private function waitForFrame(int $expectedCode, float $timeoutSec): ?array
    {
        $deadline = microtime(true) + $timeoutSec;
        while (microtime(true) < $deadline) {
            $payload = $this->serial->readFrame();
            if ($payload !== null) {
                $packet = Packet::decode($payload);
                if (($packet['code'] ?? -1) === $expectedCode) {
                    return $packet;
                }
                if (in_array($packet['type'] ?? '', ['contact', 'new_advert'], true)) {
                    $this->queuedAdvertPackets[] = $packet;
                }
            }
            usleep(10000); // 10 ms
        }
        return null;
    }

    private function verifyBbs(): void
    {
        $this->log('BBS verify: checking /api/verify');
        $response = $this->api->verify();
        if ($response === null) {
            $err = $this->api->getLastError();
            $this->log('BBS verify failed' . (($err !== null && $err !== '') ? ': ' . $err : ''));
            return;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($pretty !== false) {
                foreach (explode("\n", $pretty) as $line) {
                    $this->log('BBS verify: ' . $line);
                }
                return;
            }
        }

        $response = trim($response);
        $this->log('BBS verify: ' . ($response !== '' ? $response : '(empty response)'));
    }

    // -------------------------------------------------------------------------
    // Inbound (radio → BBS)
    // -------------------------------------------------------------------------

    private function handleFrame(string $payload): void
    {
        $packet = Packet::decode($payload);

        if (!empty($this->config['trace'])) {
            $this->logPacket($packet);
        }

        switch ($packet['type']) {
            case 'msg_waiting':
                $this->syncPending = true;
                break;

            case 'contact_msg':
                $this->handleContactMsg($packet);
                // There may be more queued messages; keep draining.
                $this->waitingForSync = false;
                $this->syncPending    = true;
                break;

            case 'channel_msg':
                // Channel messages are not forwarded to the BBS (no node ID to reply to).
                $this->waitingForSync = false;
                $this->syncPending    = true;
                break;

            case 'new_advert':
            case 'contact':
                $this->maybeReportAdvert($packet);
                break;

            case 'no_more_msgs':
                $this->waitingForSync = false;
                $this->syncPending    = false;
                break;

            case 'device_info':
                $this->logDeviceInfo($packet);
                break;

            case 'self_info':
                $this->logSelfInfo($packet);
                $this->flushQueuedAdvertPackets();
                $this->sendStartupAdvert();
                break;
        }
    }

    private function maybeReportAdvert(array $packet): void
    {
        if (($packet['adv_type_name'] ?? '') !== 'repeater') {
            return;
        }

        if (isset($packet['has_location']) && !$packet['has_location']) {
            return;
        }

        $lat = (float)($packet['adv_lat_deg'] ?? 0.0);
        $lon = (float)($packet['adv_lon_deg'] ?? 0.0);

        if ($lat === 0.0 && $lon === 0.0) {
            return;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return;
        }

        $pubKey = (string)($packet['pub_key_hex'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $pubKey)) {
            return;
        }

        $ok = $this->api->reportAdvert([
            'pub_key_hex'   => $pubKey,
            'name'          => (string)($packet['adv_name'] ?? ''),
            'adv_type'      => (string)($packet['adv_type_name'] ?? ''),
            'latitude'      => $lat,
            'longitude'     => $lon,
            'hop_count'     => (int)($packet['out_path_len'] ?? 0),
            'timestamp_iso' => (string)($packet['lastmod_iso'] ?? $packet['last_advert_iso'] ?? gmdate('c')),
        ]);

        if (!$ok && !empty($this->config['debug'])) {
            $this->log(sprintf(
                'advert report failed for %s: %s',
                substr($pubKey, 0, 12),
                $this->api->getLastError() ?? 'unknown error'
            ));
        }
    }

    private function flushQueuedAdvertPackets(): void
    {
        if ($this->queuedAdvertPackets === []) {
            return;
        }

        $packets = $this->queuedAdvertPackets;
        $this->queuedAdvertPackets = [];
        foreach ($packets as $packet) {
            $this->maybeReportAdvert($packet);
        }
    }

    private function handleContactMsg(array $packet): void
    {
        $nodeId  = $packet['pub_key_hex']; // 12-char hex string used as node ID
        $command = trim($packet['text'] ?? '');

        if ($command === '') {
            return;
        }

        $minGap = (int)($this->config['min_command_interval_seconds'] ?? 2);
        $now    = time();
        if (isset($this->lastCommandTime[$nodeId]) && ($now - $this->lastCommandTime[$nodeId]) < $minGap) {
            $this->log(sprintf('rate limit hit for %s', $nodeId));
            return;
        }
        $this->lastCommandTime[$nodeId] = $now;

        $interface = (string)($this->config['interface'] ?? 'meshcore');
        $this->log(sprintf('cmd node=%s hops=%d: %s', $nodeId, $packet['hops'], $command));

        $response = $this->api->sendCommand($nodeId, $interface, $command);
        if (str_starts_with($response, 'ERROR:')) {
            $detail = $this->api->getLastError();
            if ($detail !== null && $detail !== '') {
                $this->log('bbs error: ' . $detail);
            }
        }
        if (!empty($this->config['debug'])) {
            $this->log(sprintf('bbs response: %s', $response === '' ? '(empty)' : $response));
        }
        if (trim($response) === '') {
            $response = 'ERROR: Empty response from BBS.';
            $this->log('bbs error: empty response body');
        }
        $this->sendResponse($nodeId, $response);
    }

    private function pollConsoleInput(): void
    {
        if (!$this->stdinInteractive || !is_resource($this->stdin)) {
            return;
        }

        $read = [$this->stdin];
        $write = $except = null;
        $ready = @stream_select($read, $write, $except, 0, 0);
        if ($ready !== 1) {
            return;
        }

        $line = fgets($this->stdin);
        if ($line === false) {
            return;
        }

        $command = strtolower(trim($line));
        if ($command === '') {
            return;
        }

        switch ($command) {
            case 'advert':
            case 'a':
                $this->serial->writeFrame(Packet::sendAdvert(false));
                $this->log('console: zero-hop advert sent');
                break;

            case 'flood':
            case 'af':
                $this->serial->writeFrame(Packet::sendAdvert(true));
                $this->log('console: flood advert sent');
                break;

            case 'help':
            case '?':
                $this->log('console commands: advert|a, flood|af, help|?, quit|exit');
                break;

            case 'quit':
            case 'exit':
                $this->log('console: stopping bridge');
                $this->running = false;
                break;

            default:
                $this->log(sprintf('console: unknown command "%s" (try: help)', $command));
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Outbound (BBS → radio)
    // -------------------------------------------------------------------------

    private function pollPending(): void
    {
        $interval = (int)($this->config['poll_interval_seconds'] ?? 30);
        if ((time() - $this->lastPollTime) < $interval) {
            return;
        }
        $this->lastPollTime = time();

        foreach ($this->getPendingPollNodeIds() as $nodeId) {
            $messages = $this->api->getPending($nodeId);
            $err = $this->api->getLastError();
            if ($err !== null && $err !== '') {
                $this->log('bbs error: ' . $err);
            }
            foreach ($messages as $msg) {
                $this->sendResponse($nodeId, $msg['payload']);
            }
        }
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function normalizePollNodeIds($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $nodeIds = [];
        foreach ($value as $nodeId) {
            if (!is_string($nodeId)) {
                continue;
            }
            $nodeId = strtolower(trim($nodeId));
            if (!preg_match('/^[0-9a-f]{12}$/', $nodeId)) {
                $this->log(sprintf('config warning: ignoring invalid poll_node_ids entry "%s"', $nodeId));
                continue;
            }
            $nodeIds[$nodeId] = true;
        }

        return array_keys($nodeIds);
    }

    /**
     * @return string[]
     */
    private function getPendingPollNodeIds(): array
    {
        return array_values(array_unique(array_merge(
            $this->pollNodeIds,
            array_keys($this->lastCommandTime)
        )));
    }

    private function sendAdverts(): void
    {
        $now = time();

        $zeroHopInterval = (int)($this->config['advert_zero_hop_interval_seconds'] ?? 0);
        if ($zeroHopInterval > 0 && ($now - $this->lastZeroHopAdvert) >= $zeroHopInterval) {
            $this->serial->writeFrame(Packet::sendAdvert(false));
            $this->lastZeroHopAdvert = $now;
            $this->log('advert: zero-hop sent');
        }

        $floodInterval = (int)($this->config['advert_flood_interval_seconds'] ?? 0);
        if ($floodInterval > 0 && ($now - $this->lastFloodAdvert) >= $floodInterval) {
            $this->serial->writeFrame(Packet::sendAdvert(true));
            $this->lastFloodAdvert = $now;
            $this->log('advert: flood sent');
        }
    }

    private function sendStartupAdvert(): void
    {
        if ($this->startupAdvertSent) {
            return;
        }

        $this->serial->writeFrame(Packet::sendAdvert(false));
        $this->serial->writeFrame(Packet::sendAdvert(true));
        $this->startupAdvertSent = true;
        $now = time();
        $this->lastZeroHopAdvert = $now;
        $this->lastFloodAdvert = $now;
        $this->log('advert: startup zero-hop and flood sent');
    }

    /**
     * Chunk a response string and send each chunk as a SendTxtMsg frame.
     *
     * @param string $nodeId  12-char hex encoding of the 6-byte pub-key prefix.
     */
    private function sendResponse(string $nodeId, string $response): void
    {
        $pubKeyPrefix = @hex2bin($nodeId);
        if ($pubKeyPrefix === false || strlen($pubKeyPrefix) !== 6) {
            $this->log(sprintf('send error: invalid node ID "%s"', $nodeId));
            return;
        }

        $maxLen  = (int)($this->config['max_send_line_length'] ?? 200);
        $delayMs = (int)($this->config['inter_chunk_delay_ms'] ?? 500);

        $lines  = explode("\n", str_replace("\r\n", "\n", $response));
        $chunks = [];
        $chunk  = '';

        foreach ($lines as $line) {
            if ($chunk !== '' && (strlen($chunk) + strlen($line) + 3) > $maxLen) {
                $chunks[] = $chunk;
                $chunk    = '';
            }
            $chunk .= ($chunk !== '' ? ' | ' : '') . $line;
        }
        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        $total = count($chunks);
        foreach ($chunks as $i => $text) {
            $prefix   = ($total > 1) ? sprintf('(%d/%d) ', $i + 1, $total) : '';
            $fullText = $prefix . $text;
            $this->log(sprintf('send to %s: %s', $nodeId, $fullText));
            try {
                $this->serial->writeFrame(Packet::sendTxtMsg($pubKeyPrefix, $fullText, 0));
            } catch (\RuntimeException $e) {
                $this->log('write error: ' . $e->getMessage());
                break;
            }
            if ($total > 1 && $i < $total - 1 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function logDeviceInfo(array $packet): void
    {
        $this->log(sprintf(
            'Device: %s  fw=%d  built=%s',
            $packet['model'] ?? '?',
            $packet['firmware_ver'] ?? 0,
            $packet['build_date'] ?? '?'
        ));
    }

    private function logSelfInfo(array $packet): void
    {
        if (!empty($packet['pub_key_hex'])) {
            $this->api->setBridgeNodeId((string)$packet['pub_key_hex']);
        }

        $this->log(sprintf(
            'Node: "%s"  key=%s...  %.3f MHz SF%d BW%g kHz',
            $packet['name'],
            substr($packet['pub_key_hex'], 0, 12),
            $packet['freq_hz'] / 1e6,
            $packet['sf'],
            $packet['bw_hz'] / 1e3
        ));
    }

    private function logPacket(array $packet): void
    {
        switch ($packet['type']) {
            case 'contact_msg':
                $this->log(sprintf(
                    'recv  DM  from=%s  hops=%d  "%s"',
                    $packet['pub_key_hex'],
                    $packet['hops'],
                    addcslashes($packet['text'] ?? '', '"\\')
                ));
                break;

            case 'channel_msg':
                $this->log(sprintf(
                    'recv  CH  channel=%d  hops=%d  "%s"',
                    $packet['channel'],
                    $packet['hops'],
                    addcslashes($packet['text'] ?? '', '"\\')
                ));
                break;

            case 'self_info':
                $this->log(sprintf(
                    'recv  SELF_INFO  name="%s"  key=%s...  freq=%dHz  sf=%d',
                    addcslashes($packet['name'] ?? '', '"\\'),
                    substr($packet['pub_key_hex'], 0, 12),
                    $packet['freq_hz'],
                    $packet['sf']
                ));
                break;

            case 'device_info':
                $this->log(sprintf(
                    'recv  DEVICE_INFO  model="%s"  fw=%d  built=%s',
                    addcslashes($packet['model'] ?? '', '"\\'),
                    $packet['firmware_ver'] ?? 0,
                    $packet['build_date'] ?? ''
                ));
                break;

            case 'contacts_start':
                $this->log(sprintf(
                    'recv  CONTACTS_START  count=%d',
                    (int)($packet['count'] ?? 0)
                ));
                break;

            case 'contact':
                $path = ((int)($packet['out_path_len'] ?? 0)) > 0 ? ($packet['out_path_hex'] ?? '') : 'direct';
                $this->log(sprintf(
                    'recv  CONTACT  type=%s name="%s" key=%s... flags=0x%02X path=%s last=%s loc=%.6f,%.6f',
                    $packet['adv_type_name'] ?? '?',
                    addcslashes((string)($packet['adv_name'] ?? ''), '"\\'),
                    substr((string)($packet['pub_key_hex'] ?? ''), 0, 12),
                    (int)($packet['flags'] ?? 0),
                    $path,
                    $packet['lastmod_iso'] ?? '?',
                    (float)($packet['adv_lat_deg'] ?? 0.0),
                    (float)($packet['adv_lon_deg'] ?? 0.0)
                ));
                break;

            case 'contacts_end':
                $this->log(sprintf(
                    'recv  CONTACTS_END  lastmod=%s',
                    $packet['most_recent_lastmod_iso'] ?? '?'
                ));
                break;

            case 'log_data':
                $msg = sprintf('recv  LOG_DATA  %d bytes', (int)($packet['raw_len'] ?? 0));
                if (isset($packet['advert']) && is_array($packet['advert'])) {
                    $adv = $packet['advert'];
                    $msg .= sprintf(
                        '  advert type=%s name="%s" key=%s... ts=%s',
                        $adv['adv_type_name'] ?? '?',
                        addcslashes((string)($adv['name'] ?? ''), '"\\'),
                        substr((string)($adv['pub_key_hex'] ?? ''), 0, 12),
                        $adv['timestamp_iso'] ?? '?'
                    );
                    if (!empty($adv['has_location'])) {
                        $msg .= sprintf(' loc=%.6f,%.6f', $adv['lat_deg'], $adv['lon_deg']);
                    }
                } else {
                    $msg .= sprintf(
                        '  ascii="%s" raw=%s',
                        addcslashes((string)($packet['ascii'] ?? ''), '"\\'),
                        $packet['raw'] ?? ''
                    );
                }
                $this->log($msg);
                break;

            case 'new_advert':
                $path = ((int)($packet['out_path_len'] ?? 0)) > 0 ? ($packet['out_path_hex'] ?? '') : 'direct';
                $this->log(sprintf(
                    'recv  NEW_ADVERT  type=%s name="%s" key=%s... flags=0x%02X path=%s last=%s loc=%.6f,%.6f',
                    $packet['adv_type_name'] ?? '?',
                    addcslashes((string)($packet['adv_name'] ?? ''), '"\\'),
                    substr((string)($packet['pub_key_hex'] ?? ''), 0, 12),
                    (int)($packet['flags'] ?? 0),
                    $path,
                    $packet['lastmod_iso'] ?? '?',
                    (float)($packet['adv_lat_deg'] ?? 0.0),
                    (float)($packet['adv_lon_deg'] ?? 0.0)
                ));
                break;

            case 'no_more_msgs':
                break;

            default:
                $parts = ['recv', sprintf('type=%s', $packet['type'])];
                if (isset($packet['code'])) {
                    $parts[] = sprintf('code=0x%02X', $packet['code']);
                }
                if (isset($packet['raw'])) {
                    $parts[] = sprintf('raw=%s', $packet['raw']);
                }
                $this->log(implode('  ', $parts));
        }
    }

    private function log(string $msg): void
    {
        $ts = date('Y-m-d H:i:s');
        echo "[{$ts}] {$msg}\n";
        flush();
    }
}
