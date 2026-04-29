<?php

namespace MeshCoreBridge;

/**
 * HTTP client for the binkterm-php packet BBS gateway API.
 *
 * Communicates with the BBS over HTTPS using the configured API key sent as a
 * Bearer token.
 */
class BbsApiClient
{
    private string $bbsUrl;
    private string $apiKey;
    private ?string $bridgeNodeId = null;

    /** Timeout for HTTP requests in seconds */
    private int $timeout;
    private ?string $lastError = null;

    public function __construct(string $bbsUrl, string $apiKey, int $timeout = 15)
    {
        $this->bbsUrl  = rtrim($bbsUrl, '/');
        $this->apiKey  = $apiKey;
        $this->timeout = $timeout;
    }

    /**
     * Send a text command to the BBS and return the plain-text response.
     *
     * Returns the response string on success, or an error string prefixed with
     * "ERROR: " that can be relayed back to the radio operator.
     */
    public function sendCommand(string $nodeId, string $interface, string $command): string
    {
        $payload = json_encode([
            'node_id'        => $nodeId,
            'bridge_node_id' => $this->bridgeNodeId,
            'interface'      => $interface,
            'command'        => $command,
        ]);

        $response = $this->post('/api/packetbbs/command', $payload);
        if ($response === null) {
            return 'ERROR: Could not reach BBS. Try again later.';
        }

        return $response;
    }

    /**
     * Poll for queued outbound messages for a node.
     *
     * Returns an array of ['id' => int, 'payload' => string] entries,
     * or an empty array if none are pending or the request fails.
     *
     * @return array<int,array{id:int,payload:string}>
     */
    public function getPending(string $nodeId): array
    {
        $query = 'node_id=' . urlencode($nodeId);
        if ($this->bridgeNodeId !== null) {
            $query .= '&bridge_node_id=' . urlencode($this->bridgeNodeId);
        }
        $url = $this->bbsUrl . '/api/packetbbs/pending?' . $query;
        $raw = $this->get($url);
        if ($raw === null) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data['messages'] ?? null) ? $data['messages'] : [];
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setBridgeNodeId(?string $bridgeNodeId): void
    {
        $this->bridgeNodeId = ($bridgeNodeId !== null && $bridgeNodeId !== '') ? $bridgeNodeId : null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function post(string $path, string $body): ?string
    {
        $this->lastError = null;
        $url  = $this->bbsUrl . $path;
        $opts = [
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Length: ' . strlen($body),
                ]),
                'content'       => $body,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];

        $ctx    = stream_context_create($opts);
        $result = $this->fetchUrl($url, $ctx);

        if ($result === false) {
            return null;
        }

        // Check HTTP status from response headers
        $status = $this->parseStatusCode($http_response_header ?? []);
        if ($status !== null && $status >= 400) {
            // Infrastructure error - don't relay to radio user
            $this->lastError = sprintf('HTTP %d from %s', $status, $url);
            return null;
        }

        return $result;
    }

    private function get(string $url): ?string
    {
        $this->lastError = null;
        $opts = [
            'http' => [
                'method'        => 'GET',
                'header'        => 'Authorization: Bearer ' . $this->apiKey,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];

        $ctx    = stream_context_create($opts);
        $result = $this->fetchUrl($url, $ctx);

        if ($result === false) {
            return null;
        }

        $status = $this->parseStatusCode($http_response_header ?? []);
        if ($status !== null && $status >= 400) {
            $this->lastError = sprintf('HTTP %d from %s', $status, $url);
            return null;
        }

        return $result;
    }

    /**
     * @param resource $context
     */
    private function fetchUrl(string $url, $context)
    {
        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });

        try {
            $result = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            $this->lastError = $error !== null ? "{$url}: {$error}" : "Request failed: {$url}";
        }

        return $result;
    }

    /**
     * @param string[] $headers
     */
    private function parseStatusCode(array $headers): ?int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                return (int)$m[1];
            }
        }
        return null;
    }
}
