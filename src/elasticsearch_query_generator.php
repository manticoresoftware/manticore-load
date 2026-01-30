<?php

/*
Copyright (c) Manticore Software Ltd.

This file is part of the manticore-load tool and is licensed under the MIT License.
For full license details, see the LICENSE file in the project root.

Source code available at: https://github.com/manticoresoftware/manticore-load
*/

/**
 * Iterates over Elasticsearch cache file: one JSON-encoded batch per line.
 * Implements Countable and IteratorAggregate for compatibility with ProgressDisplay and foreach.
 */
class EsCacheFileBatches implements IteratorAggregate, Countable {
    private $path;

    public function __construct($path) {
        $this->path = $path;
    }

    public function count(): int {
        $count = 0;
        $fh = @fopen($this->path, 'r');
        if (!$fh) {
            throw new Exception("Error: Cannot read cache file");
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    $count++;
                }
            }
        } finally {
            fclose($fh);
        }
        return $count;
    }

    public function getIterator(): Traversable {
        $fh = fopen($this->path, 'r');
        if (!$fh) {
            throw new Exception("Error: Cannot read cache file");
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if ($decoded !== null) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($fh);
        }
    }
}

/**
 * Generates Elasticsearch request bodies (bulk NDJSON or search JSON) from JSON templates
 * with the same &lt;pattern&gt; placeholders as the SQL QueryGenerator.
 * Supports cache: generate to file, then load from file or stream from disk.
 */
class ElasticsearchQueryGenerator {
    private $config;
    private $load_infos = [];
    private $load_commands = [];
    private $load_distribution = [];
    private $increment_counters = [];
    private $cache_file_name;
    private $cache_from_disk = false;
    private $process_index = 1;
    /** @var resource|false|null Stop-flag shared memory (null = not opened) */
    private $stop_shm_id = null;
    private static $supported_pattern_types = [
        'increment', 'string', 'text', 'int', 'float', 'boolean', 'array', 'array_float', 'bigint'
    ];

    /**
     * @param Configuration $config
     * @param string|null $main_script_path Path to main script (e.g. __FILE__) to open stop-signal shared memory for Ctrl-C
     */
    public function __construct(Configuration $config, $main_script_path = null) {
        $this->config = $config;
        $this->load_commands = $config->get('load_commands');
        if ($this->load_commands === null) {
            $load_command = $config->get('load_command');
            $this->load_commands = is_array($load_command) ? $load_command : [$load_command];
        }
        foreach ($this->load_commands as $command) {
            $this->load_infos[] = $this->parseLoadCommand($command);
        }
        $this->load_distribution = $config->get('load-distribution') ?? $config->get('load_distribution') ?? [];
        if (count($this->load_distribution) === 0 && count($this->load_commands) > 1) {
            $weight = 1.0 / count($this->load_commands);
            $this->load_distribution = array_fill(0, count($this->load_commands), $weight);
        }
        $this->cache_from_disk = (bool)$config->get('cache-from-disk');
        $this->process_index = (int)$config->get('process_index');
        if ($this->process_index < 1) {
            $this->process_index = 1;
        }
        if ($main_script_path !== null && $main_script_path !== '') {
            $stop_shm_key = ftok($main_script_path, 'x');
            $this->stop_shm_id = @shmop_open($stop_shm_key, 'a', 0, 0);
            if ($this->stop_shm_id === false) {
                $this->stop_shm_id = null;
            }
        }
        srand(42);
    }

    private function isStopRequested() {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        if ($this->stop_shm_id === null || $this->stop_shm_id === false) {
            return false;
        }
        return @shmop_read($this->stop_shm_id, 0, 1) === "\1";
    }

    /**
     * Generate a unique cache filename based on configuration and index name.
     */
    private function generateCacheFileName($indexName = '') {
        $cache_key = implode('_', [
            json_encode($this->load_commands),
            json_encode($this->load_distribution),
            $this->config->get('total'),
            $this->config->get('batch-size'),
            $this->config->get('cache-gen-workers'),
            $this->process_index,
            $indexName
        ]);
        return '/tmp/manticore_load_es_' . md5($cache_key);
    }

    /**
     * Main entry point: load from cache if present, else generate and cache.
     *
     * @param string|null $indexName Index name for bulk (included in cache key)
     * @param bool $quiet Suppress progress output
     * @return array|EsCacheFileBatches Iterable of batch strings (bulk NDJSON or search JSON)
     */
    public function getQueries($indexName = null, $quiet = false) {
        $this->cache_file_name = $this->generateCacheFileName($indexName ?? '');
        if (!file_exists($this->cache_file_name)) {
            return $this->generateAndCacheQueries($indexName, $quiet);
        }
        if (!$quiet) {
            ConsoleOutput::writeLine("Process {$this->process_index}: Using cached data from: {$this->cache_file_name}");
        }
        return $this->loadQueriesFromCache();
    }

    /**
     * Generate all batches and write to cache file. One JSON-encoded batch per line.
     */
    private function generateAndCacheQueries($indexName, $quiet) {
        $cache_file = @fopen($this->cache_file_name, 'w');
        if (!$cache_file) {
            throw new Exception("ERROR: Cannot create cache file");
        }
        $total = (int)$this->config->get('total');
        $batch_size = (int)$this->config->get('batch-size');
        $num_loads = count($this->load_infos);
        $written = 0;
        $progress_enabled = !$quiet && function_exists('posix_isatty') && @posix_isatty(STDOUT);

        if (!$quiet) {
            ConsoleOutput::writeLine("Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ...");
        }

        try {
            if ($num_loads === 1 && $this->load_infos[0]['is_index']) {
                $batch = [];
                for ($c = 0; $c < $total; $c++) {
                    if ($this->isStopRequested()) {
                        fclose($cache_file);
                        $cache_file = null;
                        @unlink($this->cache_file_name);
                        if (!$quiet) {
                            ConsoleOutput::writeLine("Process {$this->process_index}: Cache generation interrupted.");
                        }
                        return [];
                    }
                    $batch[] = $this->generateOne(0);
                    if (count($batch) === $batch_size) {
                        fwrite($cache_file, json_encode($this->formatBulk($batch, $indexName)) . "\n");
                        $written++;
                        $batch = [];
                    }
                    if (($c + 1) % 1000 == 0) {
                        $pct = (int)round(($c + 1) * 100 / $total);
                        if ($progress_enabled) {
                            ConsoleOutput::write("\r" . sprintf("%-80s\r", "") . sprintf(
                                "Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ... %d%%",
                                $pct
                            ));
                        } elseif (!$quiet) {
                            ConsoleOutput::writeLine("Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ... {$pct}%");
                        }
                    }
                }
                if (!empty($batch)) {
                    fwrite($cache_file, json_encode($this->formatBulk($batch, $indexName)) . "\n");
                    $written++;
                }
            } elseif ($num_loads === 1 && !$this->load_infos[0]['is_index']) {
                for ($c = 0; $c < $total; $c++) {
                    if ($this->isStopRequested()) {
                        fclose($cache_file);
                        $cache_file = null;
                        @unlink($this->cache_file_name);
                        if (!$quiet) {
                            ConsoleOutput::writeLine("Process {$this->process_index}: Cache generation interrupted.");
                        }
                        return [];
                    }
                    fwrite($cache_file, json_encode($this->generateOne(0)) . "\n");
                    $written++;
                    if (($c + 1) % 1000 == 0) {
                        $pct = (int)round(($c + 1) * 100 / $total);
                        if ($progress_enabled) {
                            ConsoleOutput::write("\r" . sprintf("%-80s\r", "") . sprintf(
                                "Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ... %d%%",
                                $pct
                            ));
                        } elseif (!$quiet) {
                            ConsoleOutput::writeLine("Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ... {$pct}%");
                        }
                    }
                }
            } else {
                $batch_buffers = array_fill(0, $num_loads, []);
                $c = 0;
                while ($c < $total) {
                    if ($this->isStopRequested()) {
                        fclose($cache_file);
                        $cache_file = null;
                        @unlink($this->cache_file_name);
                        if (!$quiet) {
                            ConsoleOutput::writeLine("Process {$this->process_index}: Cache generation interrupted.");
                        }
                        return [];
                    }
                    $load_index = $this->chooseLoadIndex();
                    $info = $this->load_infos[$load_index];
                    $doc = $this->generateOne($load_index);
                    if ($info['is_index']) {
                        $batch_buffers[$load_index][] = $doc;
                        if (count($batch_buffers[$load_index]) >= $batch_size) {
                            $body = $this->formatBulk($batch_buffers[$load_index], $indexName);
                            fwrite($cache_file, json_encode($body) . "\n");
                            $written++;
                            $batch_buffers[$load_index] = [];
                        }
                    } else {
                        fwrite($cache_file, json_encode($doc) . "\n");
                        $written++;
                    }
                    $c++;
                    if ($c % 1000 == 0) {
                        $pct = (int)round($c * 100 / $total);
                        if ($progress_enabled) {
                            ConsoleOutput::write("\r" . sprintf("%-80s\r", "") . sprintf(
                                "Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ... %d%%",
                                $pct
                            ));
                        } elseif (!$quiet) {
                            ConsoleOutput::writeLine("Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ... {$pct}%");
                        }
                    }
                }
                foreach ($batch_buffers as $buffer) {
                    if (!empty($buffer)) {
                        fwrite($cache_file, json_encode($this->formatBulk($buffer, $indexName)) . "\n");
                        $written++;
                    }
                }
            }
        } finally {
            if ($cache_file !== null && is_resource($cache_file)) {
                fclose($cache_file);
            }
        }

        if (!$quiet) {
            if ($progress_enabled) {
                ConsoleOutput::write(sprintf("\r%-80s\r", ""));
            }
            ConsoleOutput::writeLine("Process {$this->process_index}: Generating new data cache {$this->cache_file_name} ... 100%");
        }

        return $this->loadQueriesFromCache();
    }

    private function loadQueriesFromCache() {
        if ($this->cache_from_disk) {
            return new EsCacheFileBatches($this->cache_file_name);
        }
        $this->warnIfCacheLikelyTooLarge();
        $lines = file($this->cache_file_name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new Exception("Error: Cannot read cache file");
        }
        $batches = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if ($decoded !== null) {
                $batches[] = $decoded;
            }
        }
        return $batches;
    }

    private function warnIfCacheLikelyTooLarge() {
        if ($this->config->get('quiet')) {
            return;
        }
        $limit_bytes = $this->getMemoryLimitBytes();
        if ($limit_bytes <= 0) {
            return;
        }
        $cache_size = @filesize($this->cache_file_name);
        if ($cache_size === false || $cache_size <= 0) {
            return;
        }
        if ($cache_size < ($limit_bytes / 4)) {
            return;
        }
        $limit_mb = (int)round($limit_bytes / (1024 * 1024));
        $size_mb = (int)round($cache_size / (1024 * 1024));
        ConsoleOutput::writeLine(
            "Process {$this->process_index}: Cache file is {$size_mb}MB with PHP memory limit {$limit_mb}MB. " .
            "Consider --cache-from-disk to avoid out-of-memory errors."
        );
    }

    private function getMemoryLimitBytes() {
        $raw = trim((string)ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }
        $unit = strtolower(substr($raw, -1));
        $value = (int)$raw;
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int)$raw;
        }
    }

    /**
     * Parse JSON template: find &lt;pattern&gt; placeholders and determine if index (document) or search.
     */
    private function parseLoadCommand($command) {
        $patterns = [];
        $pattern_occurrences = [];
        $types_regex = implode('|', self::$supported_pattern_types);
        if (preg_match_all('/<((' . $types_regex . ')[^>]*)>/', $command, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $match) {
                $pattern_text = $match[0];
                $offset = $match[1];
                if (!isset($patterns[$pattern_text])) {
                    $patterns[$pattern_text] = QueryGenerator::parsePattern($pattern_text);
                }
                $pattern_occurrences[] = [
                    'text' => $pattern_text,
                    'offset' => $offset,
                    'length' => strlen($pattern_text) + 2,
                ];
            }
        }
        $is_index = !preg_match('/^\s*\{\s*"query"/', $command);
        return [
            'command' => $command,
            'patterns' => $patterns,
            'pattern_occurrences' => $pattern_occurrences,
            'is_index' => $is_index,
        ];
    }

    /**
     * Generate a value for JSON substitution (string to replace &lt;pattern&gt; so result is valid JSON).
     */
    private function generateValueForJson($pattern, $load_index) {
        if (!is_array($pattern) || !isset($pattern['type'])) {
            throw new Exception("Invalid pattern format");
        }
        switch ($pattern['type']) {
            case 'exact':
                $enc = json_encode($pattern['value']);
                return is_string($pattern['value']) ? substr($enc, 1, -1) : $enc;
            case 'increment':
                if (!isset($this->increment_counters[$load_index])) {
                    $this->increment_counters[$load_index] = [];
                }
                $key = json_encode($pattern);
                if (!isset($this->increment_counters[$load_index][$key])) {
                    $this->increment_counters[$load_index][$key] = ($pattern['start'] ?? 1) - 1;
                }
                return (string)(++$this->increment_counters[$load_index][$key]);
            case 'string':
                $v = QueryGenerator::generateRandomString($pattern['min_length'] ?? 3, $pattern['max_length'] ?? 10);
                $enc = json_encode($v);
                return substr($enc, 1, -1);
            case 'text':
                $v = QueryGenerator::generateRandomText(
                    $pattern['min_words'] ?? 20,
                    $pattern['max_words'] ?? 300,
                    $pattern['file_path'] ?? null
                );
                $enc = json_encode($v);
                return substr($enc, 1, -1);
            case 'int':
            case 'bigint':
                return (string)rand($pattern['min'] ?? 0, $pattern['max'] ?? PHP_INT_MAX);
            case 'float':
                $decimals = $pattern['decimals'] ?? 8;
                $scale = pow(10, $decimals);
                $v = round(
                    ($pattern['min'] ?? 0) + mt_rand() / mt_getrandmax() * (($pattern['max'] ?? 0) - ($pattern['min'] ?? 0)),
                    $decimals
                );
                return (string)$v;
            case 'boolean':
                return rand(0, 1) ? 'true' : 'false';
            case 'array':
                $size = rand($pattern['min_size'], $pattern['max_size']);
                $arr = array_map(function () use ($pattern) {
                    return rand($pattern['min_value'], $pattern['max_value']);
                }, range(1, $size));
                return json_encode($arr);
            case 'array_float':
                $size = rand($pattern['min_size'], $pattern['max_size']);
                $arr = array_map(function () use ($pattern) {
                    return round(
                        $pattern['min_value'] + mt_rand() / mt_getrandmax() * ($pattern['max_value'] - $pattern['min_value']),
                        8
                    );
                }, range(1, $size));
                return json_encode($arr);
            default:
                throw new Exception("Unknown pattern type: {$pattern['type']}");
        }
    }

    /**
     * Generate one document or search body by replacing patterns in the template.
     */
    private function generateOne($load_index) {
        $template = $this->load_infos[$load_index]['command'];
        $occurrences = $this->load_infos[$load_index]['pattern_occurrences'];
        usort($occurrences, function ($a, $b) {
            return $b['offset'] - $a['offset'];
        });
        foreach ($occurrences as $occ) {
            $pattern_text = $occ['text'];
            $pattern = $this->load_infos[$load_index]['patterns'][$pattern_text];
            $value = $this->generateValueForJson($pattern, $load_index);
            $template = substr_replace(
                $template,
                $value,
                $occ['offset'] - 1,
                $occ['length']
            );
        }
        return $template;
    }

    /**
     * Choose load index by distribution (same as QueryGenerator).
     */
    private function chooseLoadIndex() {
        $rand = rand() / getrandmax();
        $cumulative = 0.0;
        foreach ($this->load_distribution as $index => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $index;
            }
        }
        return count($this->load_distribution) - 1;
    }

    /**
     * Generate all request bodies (bulk NDJSON or search body strings). Yields one string per batch.
     *
     * @param string|null $indexName Index name for bulk action line (optional; when set, action includes _index)
     * @return Generator<string>
     */
    public function generateQueries($indexName = null) {
        $total = (int)$this->config->get('total');
        $batch_size = (int)$this->config->get('batch-size');
        $num_loads = count($this->load_infos);

        if ($num_loads === 1 && $this->load_infos[0]['is_index']) {
            $batch = [];
            for ($c = 0; $c < $total; $c++) {
                $batch[] = $this->generateOne(0);
                if (count($batch) === $batch_size) {
                    yield $this->formatBulk($batch, $indexName);
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                yield $this->formatBulk($batch, $indexName);
            }
            return;
        }

        if ($num_loads === 1 && !$this->load_infos[0]['is_index']) {
            for ($c = 0; $c < $total; $c++) {
                yield $this->generateOne(0);
            }
            return;
        }

        $batch_buffers = array_fill(0, $num_loads, []);
        $c = 0;
        while ($c < $total) {
            $load_index = $this->chooseLoadIndex();
            $info = $this->load_infos[$load_index];
            $doc = $this->generateOne($load_index);
            if ($info['is_index']) {
                $batch_buffers[$load_index][] = $doc;
                if (count($batch_buffers[$load_index]) >= $batch_size) {
                    yield $this->formatBulk($batch_buffers[$load_index], $indexName);
                    $batch_buffers[$load_index] = [];
                }
            } else {
                yield $doc;
            }
            $c++;
        }
        foreach ($batch_buffers as $buffer) {
            if (!empty($buffer)) {
                yield $this->formatBulk($buffer, $indexName);
            }
        }
    }

    private function formatBulk(array $docs, $indexName = null) {
        $lines = [];
        $action = $indexName !== null && $indexName !== ''
            ? json_encode(['index' => ['_index' => $indexName]])
            : '{"index":{}}';
        foreach ($docs as $doc) {
            $lines[] = $action;
            $lines[] = $doc;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Whether the workload is index (bulk) rather than search.
     */
    public function isIndexLoad() {
        foreach ($this->load_infos as $info) {
            if ($info['is_index']) {
                return true;
            }
        }
        return false;
    }
}
