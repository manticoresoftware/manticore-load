<?php

/*
Copyright (c) Manticore Software Ltd.

This file is part of the manticore-load tool and is licensed under the MIT License.
For full license details, see the LICENSE file in the project root.

Source code available at: https://github.com/manticoresoftware/manticore-load
*/

/**
 * ColorTrait provides terminal color formatting functionality
 * Includes color constants and methods to enable/disable and apply colors
 */
trait ColorTrait {
    private const COLOR_GREEN = "\033[32m";
    private const COLOR_BLUE = "\033[34m";
    private const COLOR_YELLOW = "\033[33m";
    private const COLOR_RED = "\033[31m";
    private const COLOR_RESET = "\033[0m";

    private static bool $useColors = true;

    public static function setColorization(bool $enabled): void {
        self::$useColors = $enabled;
    }

    private static function colorize(string $text, string $color): string {
        return self::$useColors ? $color . $text . self::COLOR_RESET : $text;
    }
} 

/**
 * ProgressDisplay handles the real-time display of data loading progress
 * Supports both single and multi-process progress monitoring
 */
class ProgressDisplay {
    use ColorTrait;

    /**
     * Format string for progress display output
     * Columns: Time, Elapsed, Progress, QPS, DPS, CPU, Workers, Chunks, Merging, Size growth, Size, Docs
     */
    private static string $PROGRESS_FORMAT = "%-8s  %-8s  %-8s  %-6s  %-10s | %-9s  %-8s  %-7s  %-8s  %-11s  %-10s  %-8s\n";

    private bool $quiet;
    private int $total_batches;
    private bool $is_insert;
    private bool $use_histograms;
    private ?object $statistics;
    private float $last_update_time;
    private int $last_processed_batches = 0;
    private float $start_time;

    private static ?array $prev_values = null;
    private static ?float $prev_time = null;

    private $tempFile;
    private string $tempFilePath;

    // Add new class property to track processed lines
    private static $processedLines = [];

    // Add new property to store file handles and positions
    private static $fileHandles = [];
    private static $filePositions = [];

    // Add new property to store last known stats
    private static $lastKnownStats = [];

    /**
     * Initialize progress display
     * 
     * @param bool $quiet Suppress output if true
     * @param int $total_batches Total number of batches to process
     * @param bool $is_insert Whether operation is insert (vs update)
     * @param bool $use_histograms Whether histograms are enabled
     * @param ?object $statistics Statistics collector object
     */
    public function __construct(bool $quiet, int $total_batches, bool $is_insert, bool $use_histograms, ?object $statistics) {
        $this->quiet = $quiet;
        $this->total_batches = $total_batches;
        $this->is_insert = $is_insert;
        $this->use_histograms = $use_histograms;
        $this->statistics = $statistics;
        $this->start_time = microtime(true);
        $this->last_update_time = $this->start_time;

        if (!$quiet) {
            $this->printHeader();
        }

        $this->initializeTempFile();
    }

    /**
     * Creates temporary file to store progress data
     * Used for inter-process communication in multi-process mode
     * 
     * @throws RuntimeException if file creation fails
     */
    private function initializeTempFile(): void {
        $pid = getmypid();
        $randomStr = bin2hex(random_bytes(4));
        $this->tempFilePath = "/tmp/manticore_load_progress_{$pid}_{$randomStr}";
        
        if (file_exists($this->tempFilePath)) {
            unlink($this->tempFilePath);
        }
        $this->tempFile = @fopen($this->tempFilePath, 'w');
        if ($this->tempFile === false) {
            throw new RuntimeException("Failed to create temporary progress file: {$this->tempFilePath}");
        }
        
        register_shutdown_function([$this, 'cleanup']);
    }

    public function cleanup(): void {
        if ($this->tempFile && is_resource($this->tempFile)) {
            @fclose($this->tempFile);
        }
        if (file_exists($this->tempFilePath)) {
            @unlink($this->tempFilePath);
        }
    }

    private function printHeader() {
        if ($this->quiet) {
            return;
        }
        // Extract column widths from PROGRESS_FORMAT
        preg_match_all('/%-(\d+)s/', self::$PROGRESS_FORMAT, $matches);
        $widths = $matches[1];
        
        // Color the headers
        $headers = [
            self::colorize(str_pad("Time", $widths[0]), self::COLOR_BLUE),
            self::colorize(str_pad("Elapsed", $widths[1]), self::COLOR_BLUE),
            self::colorize(str_pad("Progress", $widths[2], ' '), self::COLOR_GREEN),
            self::colorize(str_pad("QPS", $widths[3], ' '), self::COLOR_GREEN),
            self::colorize(str_pad("DPS", $widths[4], ' '), self::COLOR_GREEN),
            self::colorize(str_pad("CPU", $widths[5]), self::COLOR_YELLOW),
            self::colorize(str_pad("Workers", $widths[6]), self::COLOR_YELLOW),
            self::colorize(str_pad("Chunks", $widths[7]), self::COLOR_YELLOW),
            self::colorize(str_pad("Merging", $widths[8]), self::COLOR_RED),
            self::colorize(str_pad("Disk growth", $widths[9]), self::COLOR_YELLOW),
            self::colorize(str_pad("Disk", $widths[10]), self::COLOR_YELLOW),
            self::colorize(str_pad("Inserted", $widths[11]), self::COLOR_YELLOW)
        ];
        
        $header_length = strlen(sprintf(self::$PROGRESS_FORMAT, ...array_map(function($h) {
            return preg_replace('/\033\[\d+m/', '', $h);
        }, $headers)));        
    }

    /**
     * Updates progress display with current statistics
     * Called periodically during data loading
     * 
     * @param int $processed_batches Number of batches processed so far
     * @param int $batch_size Size of each batch
     * @param object $common_monitoring Monitoring object with stats
     * @param object $load_stats Load statistics object
     */
    public function update($processed_batches, $batch_size, $common_monitoring, $load_stats) {
        $stats = $common_monitoring->getStats();

        $now = microtime(true);
        $interval_elapsed = $now - $this->last_update_time;
        $total_elapsed = $now - $this->start_time;
        $interval_batches = $processed_batches - $this->last_processed_batches;

        $qps = self::calculateQPS($interval_batches, $interval_elapsed);
        $load_stats->addQps($qps);
        $progress = $this->total_batches > 0 ? round($processed_batches * 100 / $this->total_batches) . "%" : "N/A";
        
        $total_docs = $processed_batches * ($this->is_insert ? $batch_size : 1);
        $dps = $interval_elapsed > 0 ? ($total_docs / $interval_elapsed) : 0;
        
        $time = date('H:i:s');
        $stats['time'] = $time;
        $stats['elapsed'] = $total_elapsed;
        $stats['progress'] = $progress;
        $stats['qps'] = (string)$qps;
        $stats['dps'] = $this->is_insert ? ($dps >= 1000 ? sprintf("%.1fK", $dps/1000/$total_elapsed) : sprintf("%.0f", $dps/$total_elapsed)) : "-";
        $stats['cpu'] = sprintf("%.4s", self::getCpuUsage());
        $stats['workers'] = (string)$stats['thread_count'];
        $stats['chunks'] = (string)$stats['disk_chunks'];
        $stats['is_optimizing'] = $stats['is_optimizing'] ? "yes" : "";

        $this->last_update_time = $now;
        $this->last_processed_batches = $processed_batches;

        if (!$this->quiet) {
            $this->saveProgress($stats);
        }
    }

    private function formatProgressLine($time, $elapsed, $progress, $qps, $dps, $cpu, $workers, $chunks, $merging, $write_speed, $size, $docs) {
        // Extract column widths from PROGRESS_FORMAT
        preg_match_all('/%-(\d+)s/', self::$PROGRESS_FORMAT, $matches);
        $widths = $matches[1];
        
        // Format values with colors using widths from format string
        $time = self::colorize(str_pad($time, $widths[0]), self::COLOR_BLUE);
        $elapsed = self::colorize(str_pad(self::formatElapsedTime($elapsed), $widths[1]), self::COLOR_BLUE);
        $progress = self::colorize(str_pad($progress, $widths[2]), self::COLOR_GREEN);
        $qps = self::colorize(str_pad($qps, $widths[3]), self::COLOR_GREEN);
        $dps = self::colorize(str_pad($dps, $widths[4]), self::COLOR_GREEN);
        $cpu = self::colorize(str_pad($cpu, $widths[5]), self::COLOR_YELLOW);
        $workers = self::colorize(str_pad($workers, $widths[6]), self::COLOR_YELLOW);
        $chunks = self::colorize(str_pad($chunks, $widths[7]), self::COLOR_YELLOW);
        $merging = $merging ? self::colorize(str_pad($merging, $widths[8]), self::COLOR_RED) : str_pad($merging, $widths[8]);
        $write_speed = self::colorize(str_pad($write_speed, $widths[9]), self::COLOR_YELLOW);
        $size = self::colorize(str_pad($size, $widths[10]), self::COLOR_YELLOW);
        $docs = self::colorize(str_pad($docs, $widths[11]), self::COLOR_YELLOW);
        
        return sprintf(self::$PROGRESS_FORMAT,
            $time, $elapsed, $progress, $qps, $dps, $cpu, $workers,
            $chunks, $merging, 
            self::formatBytes($write_speed),
            self::formatBytes($size),
            self::formatNumber($docs)
        );
    }

    public static function calculateQPS($batches, $time) {
        return $time > 0 ? round($batches / max(0.001, $time)) : 0;
    }

    public static function formatElapsedTime($seconds) {
        if ($seconds < 1) {
            return sprintf("%dms", round($seconds * 1000));
        } elseif ($seconds < 60) {
            return sprintf("%.1fs", $seconds);
        } else {
            return sprintf("%02d:%02d", (int)($seconds/60), (int)$seconds%60);
        }
    }

    /**
     * Formats file size in human readable format (B, KB, MB, GB)
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size with units
     */
    public static function formatBytes($bytes) {
        $negative = $bytes < 0;
        $bytes = abs($bytes);
        
        if ($bytes >= 1024*1024*1024) {
            $formatted = sprintf("%.2fGB", $bytes / (1024*1024*1024));
        } elseif ($bytes >= 1024*1024) {
            $formatted = sprintf("%dMB", round($bytes / (1024*1024)));
        } elseif ($bytes >= 1024) {
            $formatted = sprintf("%dKB", round($bytes / 1024));
        } else {
            $formatted = sprintf("%dB", $bytes);
        }
        
        return ($negative ? '-' : '') . $formatted;
    }

    /**
     * Gets current CPU usage percentage
     * Returns N/A on macOS or if unable to read /proc/stat
     * 
     * @return string CPU usage with % or "N/A"
     */
    public static function getCpuUsage() {
        
        if (PHP_OS === 'Darwin') {  // macOS
            return "N/A";
        }
        
        $curr = file_get_contents('/proc/stat');
        $curr_time = microtime(true);
        
        if ($curr === false) return "N/A";
        
        $curr_values = explode(' ', trim(explode("\n", $curr)[0]));
        $curr_values = array_values(array_filter(array_slice($curr_values, 1), 'strlen'));
        $curr_values = array_map('intval', $curr_values);
        
        if (self::$prev_values === null) {
            self::$prev_values = $curr_values;
            self::$prev_time = $curr_time;
            return "N/A";
        }
        
        $time_delta = $curr_time - self::$prev_time;
        if ($time_delta < 0.1) {  // Less than 100ms since last check
            return "N/A";
        }
        
        $curr_total = array_sum($curr_values);
        $prev_total = array_sum(self::$prev_values);
        
        $curr_idle = $curr_values[3];
        $prev_idle = self::$prev_values[3];
        
        if (($curr_total - $prev_total) == 0) {
            return "N/A";
        } else {
            $cpu = round((1 - ($curr_idle - $prev_idle) / ($curr_total - $prev_total)) * 100, 1);
        }
        
        self::$prev_values = $curr_values;
        self::$prev_time = $curr_time;
        
        return round($cpu)."%";
    }

    public function showFinalProgress($processed_batches, $batch_size, $start_time, $last_processed_batches, $last_stats_time, $monitoring, $statistics) {
        if ($this->quiet) {
            return;
        }

        $this->start_time = $start_time;
        $this->last_update_time = $last_stats_time;
        $this->last_processed_batches = $last_processed_batches;
        
        $this->update($processed_batches, $batch_size, $monitoring, $statistics);
    }

    public function saveProgress($stats) {
        // Write progress data to temp file
        $stats['pid'] = getmypid();
       
        // Check if parent process is still running
        $ppid = posix_getppid();
        if ($ppid == 1 || !@posix_kill($ppid, 0)) {  // ppid=1 means parent died and process was reparented to init
            fwrite(STDERR, "ERROR: Parent process is no longer running. Exiting...\n");
            exit(1);
        }
        
        if ($this->tempFile) {
            fwrite($this->tempFile, json_encode($stats) . "\n");
        }
    }

    /**
     * Monitors progress files from multiple worker processes
     * Combines and displays progress from all processes
     * 
     * @param array $pids Array of worker process IDs to monitor
     */
    public static function monitorProgressFiles($pids, $config) {
        if (empty($pids)) {
            return;
        }

        $linesBeforeHeader = 20;
        $linesPrinted = 0;
        $startTime = microtime(true);

        // Create a mapping of PIDs to simple indices (1-based)
        $pidToIndex = array_flip(array_values($pids));
        array_walk($pidToIndex, function(&$value) {
            $value += 1;  // Convert to 1-based index
        });

        // Calculate widths needed for multi-process display
        $widths = [
            'time' => 8,
            'elapsed' => 8,
        ];
        
        // Add widths for each process's columns
        for ($i = 1; $i <= count($pids); $i++) {
            $widths["progress_$i"] = 10;
        }
        for ($i = 1; $i <= count($pids); $i++) {
            $widths["qps_$i"] = 7;
        }
        for ($i = 1; $i <= count($pids); $i++) {
            $widths["dps_$i"] = 7;
        }
        
        // Add widths for common columns
        $widths = array_merge($widths, [
            'cpu' => 6,
            'workers' => 9,
            'chunks' => 8,
            'merging' => 8,
            'growth' => 11,
            'size' => 10,
            'inserted' => 8
        ]);

        // Define new format string with adjusted widths
        $format_parts = array_map(function($width) {
            return "%-{$width}s";
        }, array_values($widths));
        
        $multiProcessFormat = implode("  ", $format_parts) . "\n";

        // Create display instance with modified format
        $display = new self(false, 100, true, false, null);
        self::$PROGRESS_FORMAT = $multiProcessFormat;

        // Prepare process-specific headers
        $headers = [
            self::colorize(str_pad("Time", $widths['time']), self::COLOR_BLUE),
            self::colorize(str_pad("Elapsed", $widths['elapsed']), self::COLOR_BLUE),
        ];

        // Add Progress, QPS, DPS headers - simplified for single process
        if (count($pids) === 1) {
            $headers[] = self::colorize(str_pad("Progress", $widths["progress_1"]), self::COLOR_GREEN);
            $headers[] = self::colorize(str_pad("QPS", $widths["qps_1"]), self::COLOR_GREEN);
            $headers[] = self::colorize(str_pad("DPS", $widths["dps_1"]), self::COLOR_GREEN);
        } else {
            // Add Progress headers
            for ($i = 1; $i <= count($pids); $i++) {
                $headers[] = self::colorize(str_pad(sprintf("%d:Progress", $i), $widths["progress_$i"]), self::COLOR_GREEN);
            }
            // Add QPS headers
            for ($i = 1; $i <= count($pids); $i++) {
                $headers[] = self::colorize(str_pad(sprintf("%d:QPS", $i), $widths["qps_$i"]), self::COLOR_GREEN);
            }
            // Add DPS headers
            for ($i = 1; $i <= count($pids); $i++) {
                $headers[] = self::colorize(str_pad(sprintf("%d:DPS", $i), $widths["dps_$i"]), self::COLOR_GREEN);
            }
        }

        // Add common headers
        $headers = array_merge($headers, [
            self::colorize(str_pad("CPU", $widths['cpu']), self::COLOR_YELLOW),
            self::colorize(str_pad("Workers", $widths['workers']), self::COLOR_YELLOW),
            self::colorize(str_pad("Chunks", $widths['chunks']), self::COLOR_YELLOW),
            self::colorize(str_pad("Merging", $widths['merging']), self::COLOR_RED),
            self::colorize(str_pad("Disk growth", $widths['growth']), self::COLOR_YELLOW),
            self::colorize(str_pad("Disk", $widths['size']), self::COLOR_YELLOW),
            self::colorize(str_pad("Inserted", $widths['inserted']), self::COLOR_YELLOW)
        ]);

        // Prepare header printing function
        $printHeader = function() use ($headers, $multiProcessFormat) {
            $header_length = strlen(sprintf(self::$PROGRESS_FORMAT, ...array_map(function($h) {
                return preg_replace('/\033\[\d+m/', '', $h);
            }, $headers)));
            
            echo str_repeat("-", $header_length - 1) . "\n";
            printf(self::$PROGRESS_FORMAT, ...$headers);
            echo str_repeat("-", $header_length - 1) . "\n";
        };

        $headerPrinted = false;  // Add flag to track if header has been printed

        // Initialize processed lines counter for each PID
        foreach ($pids as $pid) {
            self::$processedLines[$pid] = 0;
        }

        // Initialize file tracking for each PID
        foreach ($pids as $pid) {
            self::$filePositions[$pid] = 0;
            self::$fileHandles[$pid] = null;
        }

        while (true) {
            $stats = [];
            $currentTime = microtime(true);
            
            $runningPids = [];
            // Check which processes are still running
            foreach ($pids as $pid) {
                $status = 0;
                $res = pcntl_waitpid($pid, $status, WNOHANG);
                if ($res === 0) {  // Process is still running
                    $runningPids[] = $pid;
                }
            }
            
            if (empty($runningPids)) {
                self::$lastKnownStats = [];  // Clear stored stats
                // Clean up file handles
                foreach (self::$fileHandles as $fh) {
                    if (is_resource($fh)) {
                        fclose($fh);
                    }
                }
                self::$fileHandles = [];
                self::$filePositions = [];
                break;
            }
            
            // Try to read new lines from each process file
            foreach ($runningPids as $pid) {
                $pattern = "/tmp/manticore_load_progress_{$pid}_*";
                $files = glob($pattern);
                
                if (!empty($files)) {
                    $filepath = $files[0];
                    
                    if (file_exists($filepath)) {
                        // Open file if not already opened
                        if (!isset(self::$fileHandles[$pid]) || !is_resource(self::$fileHandles[$pid])) {
                            self::$fileHandles[$pid] = @fopen($filepath, 'r');
                            if (!self::$fileHandles[$pid]) {
                                continue;
                            }
                        }
                        
                        $fh = self::$fileHandles[$pid];
                        // Seek to last position
                        fseek($fh, self::$filePositions[$pid]);
                        
                        // Read all new lines
                        $lastLine = null;
                        while (($line = fgets($fh)) !== false) {
                            $lastLine = $line;
                        }
                        
                        // Store current position
                        self::$filePositions[$pid] = ftell($fh);
                        
                        // Process last line if we have one
                        if ($lastLine !== null) {
                            $processStats = json_decode(trim($lastLine), true);
                            if ($processStats) {
                                $stats[$pid] = $processStats;
                                // Store last known stats for this PID
                                self::$lastKnownStats[$pid] = $processStats;
                            }
                        } else if (isset(self::$lastKnownStats[$pid])) {
                            // Use last known stats if no new data
                            $stats[$pid] = self::$lastKnownStats[$pid];
                        }
                    }
                }
            }
            
            if (!empty($stats)) {
                // Print header if this is the first data we're showing
                if (!$headerPrinted && !$config->get('quiet')) {
                    $printHeader();
                    $headerPrinted = true;
                }

                // Take common stats from any process but calculate time independently
                $anyStats = reset($stats);
                
                // Create array of tables and their documents
                $tablesDocs = [];
                foreach ($stats as $pid => $stat) {
                    $processIndex = $pidToIndex[$pid];
                    $processConfig = $config->getProcessConfig($processIndex);
                    // Skip if process config not found
                    if (!$processConfig) {
                        continue;
                    }
                    
                    // Only count documents for insert operations
                    if ($processConfig['load_type'] === 'insert') {
                        $table = $processConfig['table'] ?? '';
                        if (!isset($tablesDocs[$table])) {
                            $tablesDocs[$table] = 0;
                        }
                        // Take max docs count for processes working with the same table
                        $tablesDocs[$table] = max($tablesDocs[$table], $stat['indexed_documents']);
                    }
                }
                
                $combinedStats = [
                    'time' => date('H:i:s'),
                    'elapsed' => $currentTime - $startTime,
                    'cpu' => $anyStats['cpu'],
                    'workers' => $anyStats['workers'],
                    'chunks' => array_sum(array_column($stats, 'disk_chunks')),
                    'is_optimizing' => array_reduce($stats, function($carry, $item) {
                        return $carry || $item['is_optimizing'];
                    }, false),
                    'growth_rate' => array_sum(array_column($stats, 'growth_rate')),
                    'size' => array_sum(array_column($stats, 'disk_bytes')),
                    'inserted' => array_sum($tablesDocs) // Sum documents across different tables
                ];
            } else if (!empty(self::$lastKnownStats)) {
                // Use last known stats for display
                if (!$headerPrinted && !$config->get('quiet')) {
                    $printHeader();
                    $headerPrinted = true;
                }

                // Take common stats from last known values
                $anyStats = reset(self::$lastKnownStats);
                
                // Calculate tablesDocs using last known values
                $tablesDocs = [];
                foreach (self::$lastKnownStats as $pid => $stat) {
                    $processIndex = $pidToIndex[$pid];
                    $processConfig = $config->getProcessConfig($processIndex);
                    if (!$processConfig) {
                        continue;
                    }
                    
                    if ($processConfig['load_type'] === 'insert') {
                        $table = $processConfig['table'] ?? '';
                        if (!isset($tablesDocs[$table])) {
                            $tablesDocs[$table] = 0;
                        }
                        $tablesDocs[$table] = max($tablesDocs[$table], $stat['indexed_documents']);
                    }
                }

                $combinedStats = [
                    'time' => date('H:i:s'),
                    'elapsed' => $currentTime - $startTime,
                    'cpu' => $anyStats['cpu'],
                    'workers' => $anyStats['workers'],
                    'chunks' => array_sum(array_column(self::$lastKnownStats, 'disk_chunks')),
                    'is_optimizing' => array_reduce(self::$lastKnownStats, function($carry, $item) {
                        return $carry || $item['is_optimizing'];
                    }, false),
                    'growth_rate' => 0,
                    'size' => array_sum(array_column(self::$lastKnownStats, 'disk_bytes')),
                    'inserted' => array_sum($tablesDocs)
                ];
            } else {
                // No stats available at all
                // Print at least time info when no stats are available yet
                if (!$headerPrinted && !$config->get('quiet')) {
                    $printHeader();
                    $headerPrinted = true;
                }

                $combinedStats = [
                    'time' => date('H:i:s'),
                    'elapsed' => $currentTime - $startTime,
                    'cpu' => 'N/A',
                    'workers' => '-',
                    'chunks' => 0,
                    'is_optimizing' => '',
                    'growth_rate' => 0,
                    'size' => 0,
                    'inserted' => 0
                ];
            }
            
            $values = [
                self::colorize(str_pad($combinedStats['time'], $widths['time']), self::COLOR_BLUE),
                self::colorize(str_pad(self::formatElapsedTime($combinedStats['elapsed']), $widths['elapsed']), self::COLOR_BLUE)
            ];
                        
            // Add Progress values
            foreach ($pids as $pid) $values[] = str_pad(isset($stats[$pid]) ? $stats[$pid]['progress'] : '-', 10);

            // Add QPS values
            foreach ($pids as $pid) $values[] = str_pad(isset($stats[$pid]) ? $stats[$pid]['qps'] : '-', 7);

            // Add DPS values
            foreach ($pids as $pid) $values[] = str_pad(isset($stats[$pid]) ? $stats[$pid]['dps'] : '-', 7);

            // Add common values
            $values = array_merge($values, [
                self::colorize(str_pad($combinedStats['cpu'], $widths['cpu']), self::COLOR_YELLOW),
                self::colorize(str_pad($combinedStats['workers'], $widths['workers']), self::COLOR_YELLOW),
                self::colorize(str_pad($combinedStats['chunks'], $widths['chunks']), self::COLOR_YELLOW),
                self::colorize(str_pad($combinedStats['is_optimizing'], $widths['merging']), self::COLOR_RED),
                self::colorize(str_pad(self::formatBytes($combinedStats['growth_rate']), $widths['growth']), self::COLOR_YELLOW),
                self::colorize(str_pad(self::formatBytes($combinedStats['size']), $widths['size']), self::COLOR_YELLOW),
                self::colorize(str_pad(self::formatNumber($combinedStats['inserted']), $widths['inserted']), self::COLOR_YELLOW)
            ]);
        

            // Format and print the line
            printf(self::$PROGRESS_FORMAT, ...$values);
            $linesPrinted++;

            // Reprint header if needed
            if ($linesPrinted >= $linesBeforeHeader) {
                echo "\n";
                $printHeader();
                $linesPrinted = 0;
            }
            sleep(1);
        }
    }

    private static function formatNumber($number) {
        if ($number >= 1000000000) {
            return sprintf("%.1fB", $number / 1000000000);
        } elseif ($number >= 1000000) {
            return sprintf("%.1fM", $number / 1000000);
        } elseif ($number >= 1000) {
            return sprintf("%.1fK", $number / 1000);
        }
        return (string)$number;
    }
}
