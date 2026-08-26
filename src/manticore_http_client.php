<?php

/*
Copyright (c) Manticore Software Ltd.

This file is part of the manticore-load tool and is licensed under the MIT License.
For full license details, see the LICENSE file in the project root.

Source code available at: https://github.com/manticoresoftware/manticore-load
*/

/**
 * HTTP client for Manticore Search JSON API.
 * Supports synchronous requests (init, drop) and async pool for load (bulk/search).
 */
class ManticoreHttpClient {
    private $baseUrl;
    private $numSlots;
    private $multiHandle;
    private $slots = [];  // slot_id => ['handle' => curl, 'start_time' => float]
    private $slotHandles = [];  // curl handle => slot_id (for looking up which slot completed)
    private $authUser;
    private $authPassword;

    public function __construct($host, $port, $numSlots = 1, $user = null, $password = null) {
        $this->baseUrl = 'http://' . $host . ':' . (int)$port;
        $this->numSlots = max(0, (int)$numSlots);
        $this->authUser = $user;
        $this->authPassword = $password;
        $this->multiHandle = null;
        if ($this->numSlots > 0) {
            $this->multiHandle = curl_multi_init();
            if ($this->multiHandle === false) {
                throw new Exception("Cannot create cURL multi handle");
            }
            for ($i = 0; $i < $this->numSlots; $i++) {
                $this->slots[$i] = ['handle' => null, 'start_time' => null];
            }
        }
    }

    /**
     * Build full URL for path.
     */
    public function getUrl($path) {
        $path = '/' . ltrim($path, '/');
        return $this->baseUrl . $path;
    }

    /**
     * Execute a synchronous HTTP request (for init, drop, or load).
     *
     * @param string      $method      HTTP method (GET, PUT, POST, DELETE, etc.)
     * @param string      $path        Path (e.g. /my_index, /_bulk)
     * @param string|null $body        Request body or null
     * @param string      $contentType Content-Type header (default application/json; use application/x-ndjson for bulk)
     * @return array ['body' => string, 'status' => int]
     */
    public function request($method, $path, $body = null, $contentType = 'application/json') {
        $url = $this->getUrl($path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception("Cannot create cURL handle");
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType],
            CURLOPT_TIMEOUT => 300,
        ];
        if ($this->authUser !== null && $this->authUser !== '') {
            $opts[CURLOPT_USERPWD] = $this->authUser . ':' . ($this->authPassword ?? '');
        }
        curl_setopt_array($ch, $opts);
        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            throw new Exception("Manticore HTTP request failed: $error");
        }
        return ['body' => $response, 'status' => $status];
    }

    /**
     * Start an async request on the given slot (for load loop).
     *
     * @param int    $slotId Slot index (0 .. numSlots-1)
     * @param string $method HTTP method
     * @param string $path   Path
     * @param string $body   Request body
     * @param string $contentType Content-Type header (default application/json; use application/x-ndjson for bulk)
     */
    public function startRequest($slotId, $method, $path, $body, $contentType = 'application/json') {
        if ($slotId < 0 || $slotId >= $this->numSlots) {
            throw new Exception("Invalid slot id: $slotId");
        }
        if ($this->slots[$slotId]['start_time'] !== null) {
            throw new Exception("Slot $slotId already has a request in flight");
        }
        $url = $this->getUrl($path);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new Exception("Cannot create cURL handle");
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 600,
        ];
        if ($this->authUser !== null && $this->authUser !== '') {
            $opts[CURLOPT_USERPWD] = $this->authUser . ':' . ($this->authPassword ?? '');
        }
        curl_setopt_array($ch, $opts);
        $this->slotHandles[(int)$ch] = $slotId;
        $this->slots[$slotId] = ['handle' => $ch, 'start_time' => microtime(true)];
        curl_multi_add_handle($this->multiHandle, $ch);
    }

    /**
     * Wait for one in-flight request to complete.
     *
     * @return array ['slot_id' => int, 'body' => string, 'status' => int, 'latency_ms' => float]
     */
    public function waitForOne() {
        do {
            $status = curl_multi_exec($this->multiHandle, $running);
            if ($status !== CURLM_OK) {
                throw new Exception("curl_multi_exec failed: $status");
            }
            $info = curl_multi_info_read($this->multiHandle);
            if ($info !== false && $info['msg'] === CURLMSG_DONE) {
                break;
            }
            if ($running > 0) {
                curl_multi_select($this->multiHandle, 0.1);
            }
        } while ($running > 0);

        if ($info === false || $info['msg'] !== CURLMSG_DONE) {
            throw new Exception("No completed request in waitForOne");
        }
        $ch = $info['handle'];
        $slotId = $this->slotHandles[(int)$ch];
        $startTime = $this->slots[$slotId]['start_time'];
        $latencyMs = (microtime(true) - $startTime) * 1000;
        $body = curl_multi_getcontent($ch);
        if ($body === false) {
            $body = '';
        }
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($this->multiHandle, $ch);
        curl_close($ch);
        unset($this->slotHandles[(int)$ch]);
        $this->slots[$slotId] = ['handle' => null, 'start_time' => null];
        return [
            'slot_id' => $slotId,
            'body' => $body,
            'status' => $statusCode,
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Get a free slot index for starting a new request, or -1 if all are in use.
     */
    public function getFreeSlotId() {
        for ($i = 0; $i < $this->numSlots; $i++) {
            if ($this->slots[$i]['start_time'] === null) {
                return $i;
            }
        }
        return -1;
    }

    /**
     * Check if any request is in flight (for polling without blocking).
     */
    public function hasInFlight() {
        foreach ($this->slots as $slot) {
            if ($slot['start_time'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Close the multi handle and any open slots.
     */
    public function close() {
        if ($this->multiHandle === null) {
            return;
        }
        foreach ($this->slots as $slot) {
            if ($slot['handle'] !== null) {
                curl_multi_remove_handle($this->multiHandle, $slot['handle']);
                curl_close($slot['handle']);
            }
        }
        $this->slots = $this->numSlots > 0 ? array_fill(0, $this->numSlots, ['handle' => null, 'start_time' => null]) : [];
        $this->slotHandles = [];
        curl_multi_close($this->multiHandle);
        $this->multiHandle = null;
    }
}
