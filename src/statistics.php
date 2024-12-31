<?php

/*
Copyright (c) Manticore Software Ltd.

This file is part of the manticore-load tool and is licensed under the MIT License.
For full license details, see the LICENSE file in the project root.

Source code available at: https://github.com/manticoresoftware/manticore-load
*/

require_once dirname(__FILE__).'/progress_display.php';

class Statistics {
    // Stores performance metrics and statistics
    private $start_time;
    private $total_batches;
    private $threads;
    private $batch_size;
    private $completed_operations = 0;
    private $completed_queries = 0;
    private $is_insert_query;
    private $qps_stats = [];          // Stores queries per second measurements
    private $latency_histogram;       // For histogram-based latency tracking
    private $latency_stats = [];      // For simple array-based latency tracking
    private $use_histograms;          // Controls which latency tracking method to use
    private $config;                  // Configuration object
    private $process_index;           // Process index
    private static $header_shown = false; // Static flag to track if header has been shown

    /**
     * Initializes statistics tracking with specified batch size and query type
     * @param int $threads Number of threads
     * @param int $batch_size Size of operation batches
     * @param bool $is_insert_query Whether tracking inserts (true) or reads (false)
     * @param Configuration $config Configuration object
     */
    public function __construct($threads, $batch_size, $is_insert_query, $config, $process_index) {
        $this->start_time = microtime(true);
        $this->threads = $threads;
        $this->batch_size = $batch_size;
        $this->is_insert_query = $is_insert_query;
        $this->use_histograms = $config->get('latency-histograms');
        $this->config = $config;
        $this->process_index = $process_index;
        
        if ($this->use_histograms) {
            $this->latency_histogram = new LatencyHistogram();
        }
    }

    /**
     * Records completed operations and increments query counter
     * @param int $count Number of operations completed in this batch
     */
    public function addOperations($count) {
        $this->completed_operations += $count;
        $this->completed_queries++;
    }

    /**
     * Records a queries-per-second measurement
     * @param float $qps Queries per second value to record
     */
    public function addQps($qps) {
        $this->qps_stats[] = $qps;
    }

    /**
     * Records a latency measurement using either histogram or array storage
     * @param float $latency Latency value in milliseconds
     */
    public function addLatency($latency) {
        if ($this->use_histograms) {
            $this->latency_histogram->add($latency);
        } else {
            $this->latency_stats[] = $latency;
        }
    }

    /**
     * Calculates statistical summary of recorded latencies
     * @return array Contains avg, p50, p95, and p99 latency values
     */
    private function getLatencyStats() {
        if ($this->use_histograms) {
            // Use histogram-based percentile calculations for better memory efficiency
            return [
                'avg' => $this->latency_histogram->getAverage(),
                'p50' => $this->latency_histogram->getPercentile(50),
                'p95' => $this->latency_histogram->getPercentile(95),
                'p99' => $this->latency_histogram->getPercentile(99)
            ];
        } else {
            // Fall back to simple array-based calculations if histograms are disabled
            if (empty($this->latency_stats)) {
                return ['avg' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0];
            }
            return [
                'avg' => array_sum($this->latency_stats) / count($this->latency_stats),
                'p50' => $this->calculatePercentile($this->latency_stats, 50),
                'p95' => $this->calculatePercentile($this->latency_stats, 95),
                'p99' => $this->calculatePercentile($this->latency_stats, 99)
            ];
        }
    }

    /**
     * Calculates a percentile value from an array of measurements
     * @param array $array Array of numeric values
     * @param float $percentile Percentile to calculate (1-100)
     * @return float Calculated percentile value
     */
    private function calculatePercentile($array, $percentile) {
        if (empty($array)) return 0;
        sort($array);
        $index = ceil(count($array) * $percentile / 100) - 1;
        return $array[$index];
    }

    /**
     * Calculates statistical summary of QPS measurements
     * @return array Contains avg, p1, p5, p95, and p99 QPS values
     */
    private function calculateQpsStats() {
        if (empty($this->qps_stats)) {
            return ['avg' => 0, 'p1' => 0, 'p5' => 0, 'p95' => 0, 'p99' => 0];
    }
    return [
            'avg' => round(array_sum($this->qps_stats) / count($this->qps_stats)),
            'p1' => $this->calculatePercentile($this->qps_stats, 1),
            'p5' => $this->calculatePercentile($this->qps_stats, 5),
            'p95' => $this->calculatePercentile($this->qps_stats, 95),
            'p99' => $this->calculatePercentile($this->qps_stats, 99)
        ];
    }

    /**
     * Outputs performance statistics report
     * @param array $process_info Array containing process configuration and info
     */
    public function printReport($process_info) {
        $total_time = microtime(true) - $this->start_time;
        $qps_stats = $this->calculateQpsStats();
        $latency_stats = $this->getLatencyStats();

        if ($process_info['quiet']) {
            $this->printQuietReport($total_time, $qps_stats, $process_info);
        } else {
            $this->printVerboseReport($total_time, $qps_stats, $latency_stats, $process_info);
        }
    }

    /**
     * Prints performance statistics in compact tabular format
     * @param float $total_time Total elapsed time
     * @param array $qps_stats QPS statistics
     * @param array $process_info Process configuration and info
     */
    private function printQuietReport($total_time, $qps_stats, $process_info) {
        $column_name = null;
        $column_value = null;
        
        if (isset($process_info['column'])) {
            $parts = explode('/', $process_info['column'], 2);
            if (count($parts) === 2) {
                $column_name = $parts[0];
                $column_value = $parts[1];
            }
        }
        
        // Get threads and batch size
        $threads = $this->threads;
        $batch_size = $this->batch_size;
        
        if ($this->is_insert_query) {
            $format = "\n";
            $values = [];
            
            // Add custom column if specified
            if ($column_name) {
                $format .= "%-12s; ";
                $values[] = $column_name;
            }
            
            // Add Threads and Batch columns
            $format .= "%-8s; %-10s; ";
            $values[] = "Threads";
            $values[] = "Batch";
            
            $format .= "%-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s;\n";
            $values = array_merge($values, [
                "Time", "Total Docs", "Docs/Sec", "Avg QPS", "p99 QPS", "p95 QPS", "p5 QPS", "p1 QPS",
                "Lat Avg", "Lat p50", "Lat p95", "Lat p99"
            ]);
            if (!self::$header_shown) {
                vprintf($format, $values);
                self::$header_shown = true;
            }

            $format = "";
            $values = [];
            
            // Add custom column value if specified
            if ($column_value) {
                $format .= "%-12s; ";
                $values[] = $column_value;
            }
            
            // Add Threads and Batch values
            $format .= "%-8d; %-10d; ";
            $values[] = $threads;
            $values[] = $batch_size;
            
            $format .= "%-12s; %-12d; %-12d; %-12d; %-12d; %-12d; %-12d; %-12d; %-12.1f; %-12.1f; %-12.1f; %-12.1f;\n";
            $values = array_merge($values, [
                sprintf("%02d:%02d", (int)($total_time/60), (int)$total_time%60),
                $this->completed_operations,
                $total_time > 0 ? round($this->completed_operations / $total_time) : 0,
                $qps_stats['avg'], $qps_stats['p99'], $qps_stats['p95'],
                $qps_stats['p5'], $qps_stats['p1'],
                $this->getLatencyStats()['avg'],
                $this->getLatencyStats()['p50'],
                $this->getLatencyStats()['p95'],
                $this->getLatencyStats()['p99']
            ]);
            vprintf($format, $values);
        } else {
            $format = "\n";
            $values = [];
            
            if ($column_name) {
                $format .= "%-12s; ";
                $values[] = $column_name;
            }
            
            // Add Threads and Batch columns
            $format .= "%-8s; %-10s; ";
            $values[] = "Threads";
            $values[] = "Batch";
            
            $format .= "%-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s; %-12s;\n";
            $values = array_merge($values, [
                "Time", "Total Ops", "Avg QPS", "p99 QPS", "p95 QPS", "p5 QPS", "p1 QPS",
                "Lat Avg", "Lat p50", "Lat p95", "Lat p99"
            ]);
            if (!self::$header_shown) {
                vprintf($format, $values);
                self::$header_shown = true;
            }

            $format = "";
            $values = [];
            
            if ($column_value) {
                $format .= "%-12s; ";
                $values[] = $column_value;
            }
            
            // Add Threads and Batch values
            $format .= "%-8d; %-10d; ";
            $values[] = $threads;
            $values[] = $batch_size;
            
            $format .= "%-12s; %-12d; %-12d; %-12d; %-12d; %-12d; %-12d; %-12.1f; %-12.1f; %-12.1f; %-12.1f;\n";
            $values = array_merge($values, [
                sprintf("%02d:%02d", (int)($total_time/60), (int)$total_time%60),
                $this->completed_operations,
                $qps_stats['avg'], $qps_stats['p99'], $qps_stats['p95'],
                $qps_stats['p5'], $qps_stats['p1'],
                $this->getLatencyStats()['avg'],
                $this->getLatencyStats()['p50'],
                $this->getLatencyStats()['p95'],
                $this->getLatencyStats()['p99']
            ]);
            vprintf($format, $values);
        }
    }
    /**
     * Prints detailed performance statistics report
     * @param float $total_time Total elapsed time
     * @param array $qps_stats QPS statistics
     * @param array $latency_stats Latency statistics
     * @param array $process_info Process configuration and info
     */
    private function printVerboseReport($total_time, $qps_stats, $latency_stats, $process_info) {
        $output = "--------------------------------------------------------------------------------------\n";
        $output .= "Process {$process_info['process_index']} final statistics:\n";

        // Add command information
        if (isset($process_info['init_command'])) {
            $output .= sprintf("Init command:     %s\n", $process_info['init_command']);
        }
        $output .= sprintf("Load command:     %s\n", $process_info['load_command']);
        
        $output .= sprintf("Total time:       %s\n", ProgressDisplay::formatElapsedTime($total_time));
        $output .= sprintf("Total queries:    %d\n", $this->completed_queries);
        $output .= sprintf("Threads:          %d\n", $this->threads);
        $output .= sprintf("Batch size:       %d\n", $this->batch_size);
        
        if ($this->is_insert_query) {
            $output .= sprintf("Total docs:       %d\n", $this->completed_operations);
            $total_docs_per_sec = $total_time > 0 ? round($this->completed_operations / $total_time) : 0;
            $output .= sprintf("Docs per sec avg: %d\n", $total_docs_per_sec);
            
            $output .= sprintf("QPS avg:          %d\n", $qps_stats['avg']);
            $output .= sprintf("QPS 1p:           %d\n", $qps_stats['p1']);
            $output .= sprintf("QPS 5p:           %d\n", $qps_stats['p5']);
            $output .= sprintf("QPS 95p:          %d\n", $qps_stats['p95']);
            $output .= sprintf("QPS 99p:          %d\n", $qps_stats['p99']);
        } else {
            $output .= sprintf("QPS avg:          %d\n", $qps_stats['avg']);
            $output .= sprintf("QPS 95p:          %d\n", $qps_stats['p95']);
            $output .= sprintf("QPS 99p:          %d\n", $qps_stats['p99']);
        }
        
        $output .= sprintf("Latency avg:      %.1f ms\n", $latency_stats['avg']);
        $output .= sprintf("Latency 50p:      %.1f ms\n", $latency_stats['p50']);
        $output .= sprintf("Latency 95p:      %.1f ms\n", $latency_stats['p95']);
        $output .= sprintf("Latency 99p:      %.1f ms\n", $latency_stats['p99']);
        
        $output .= "--------------------------------------------------------------------------------------\n";
        
        // Print the entire output at once atomically
        fwrite(STDOUT, $output);
    }
}

class LatencyHistogram {
    // Implements memory-efficient latency tracking using bucketed histograms
    private $buckets = [];    // Stores frequency counts for latency ranges
    private $count = 0;       // Total number of measurements
    private $sum = 0;         // Sum of all latencies for average calculation

    /**
     * Initializes histogram buckets with varying granularity levels
     */
    public function __construct() {
        // Initialize histogram buckets with different granularity ranges:
        // - 1-100ms: 1ms granularity
        // - 100-1000ms: 10ms granularity
        // - 1-10sec: 100ms granularity
        // - 10-100sec: 1000ms granularity
        
        // 1-100ms with 1ms step
        for ($i = 1; $i <= 100; $i++) {
            $this->buckets[$i] = 0;
        }
        // 110-1000ms with 10ms step
        for ($i = 110; $i <= 1000; $i += 10) {
            $this->buckets[$i] = 0;
        }
        // 1100-10000ms with 100ms step
        for ($i = 1100; $i <= 10000; $i += 100) {
            $this->buckets[$i] = 0;
        }
        // 11000-100000ms with 1000ms step
        for ($i = 11000; $i <= 100000; $i += 1000) {
            $this->buckets[$i] = 0;
        }
    }

    /**
     * Adds a latency measurement to appropriate histogram bucket
     * @param float $latency Latency value in milliseconds
     */
    public function add($latency) {
        $bucket = $this->findBucket($latency);
        $this->buckets[$bucket]++;
        $this->count++;
        $this->sum += $latency;
    }

    /**
     * Determines appropriate histogram bucket for given latency
     * @param float $latency Latency value in milliseconds
     * @return int Bucket upper bound value
     */
    private function findBucket($latency) {
        if ($latency <= 100) {
            return ceil($latency);
        }
        if ($latency <= 1000) {
            return ceil($latency / 10) * 10;
        }
        if ($latency <= 10000) {
            return ceil($latency / 100) * 100;
        }
        if ($latency <= 100000) {
            return ceil($latency / 1000) * 1000;
        }
        return 100000; // Cap at 100 seconds
    }

    /**
     * Calculates specified percentile from histogram data
     * @param float $p Percentile value (1-100)
     * @return float Calculated percentile in milliseconds
     */
    public function getPercentile($p) {
        if ($this->count == 0) return 0;
        
        $target = ceil(($p / 100) * $this->count);
        $count = 0;
        foreach ($this->buckets as $bucket => $freq) {
            $count += $freq;
            if ($count >= $target) {
                // Return middle of the bucket for better approximation
                if ($bucket <= 100) {
                    return $bucket - 0.5;
                }
                if ($bucket <= 1000) {
                    return $bucket - 5;
                }
                if ($bucket <= 10000) {
                    return $bucket - 50;
                }
                return $bucket - 500;
            }
        }
        return 100000;
    }

    /**
     * Calculates average latency from all measurements
     * @return float Average latency in milliseconds
     */
    public function getAverage() {
        return $this->count > 0 ? $this->sum / $this->count : 0;
    }
}

class MonitoringStats {
    // Tracks system metrics like thread count and disk usage
    private $connection;          // MySQL connection to Manticore
    private $table_name;          // Table being monitored
    private $last_disk_bytes = 0; // Previous disk usage measurement
    private $last_disk_time;      // Timestamp of last measurement
    private $size_samples = [];   // Recent disk usage samples
    private $sample_window = 5;   // Sample retention period in seconds

    /**
     * Establishes monitoring connection to Manticore Search
     * @param string $host Manticore server host
     * @param int $port Manticore server port
     * @param string|null $table_name Optional table name to monitor
     */
    public function __construct($host, $port, $table_name = null) {
        $this->connection = mysqli_connect($host, '', '', '', $port);
        if (!$this->connection) {
            throw new Exception("Cannot create monitoring connection to Manticore: " . mysqli_connect_error());
        }
        $this->table_name = $table_name;
        $this->last_disk_time = microtime(true);
    }

    /**
     * Retrieves current system metrics
     * @return array System metrics including thread count, disk usage, etc.
     */
    public function getStats() {
        $thread_count = 0;
        $disk_chunks = 0;
        $is_optimizing = 0;
        $disk_bytes = 0;
        $ram_bytes = 0;
        $indexed_documents = 0;
        
        try {
            $result = mysqli_query($this->connection, "SHOW THREADS");
            if ($result) {
                $thread_count = mysqli_num_rows($result);
                mysqli_free_result($result);
            }
            
            if ($this->table_name) {
                $result = mysqli_query($this->connection, "SHOW TABLE {$this->table_name} STATUS");
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        switch ($row['Variable_name']) {
                            case 'disk_chunks': $disk_chunks = (int)$row['Value']; break;
                            case 'optimizing': $is_optimizing = (int)$row['Value']; break;
                            case 'disk_bytes': $disk_bytes = (int)$row['Value']; break;
                            case 'ram_bytes': $ram_bytes = (int)$row['Value']; break;
                            case 'indexed_documents': $indexed_documents = (int)$row['Value']; break;
                        }
                    }
                    mysqli_free_result($result);
                }
            }
        } catch (mysqli_sql_exception $e) {
            throw new Exception("ERROR querying status: " . $e->getMessage());
        }

        $now = microtime(true);

        // Add new sample
        $this->size_samples[] = [
            'time' => $now,
            'bytes' => $disk_bytes
        ];

        // Remove old samples
        $cutoff_time = $now - $this->sample_window;
        $this->size_samples = array_filter($this->size_samples, function($sample) use ($cutoff_time) {
            return $sample['time'] >= $cutoff_time;
        });

        $this->last_disk_bytes = $disk_bytes;
        $this->last_disk_time = $now;
        
        return [
            'thread_count' => $thread_count,
            'disk_chunks' => $disk_chunks,
            'is_optimizing' => $is_optimizing,
            'disk_bytes' => $disk_bytes,
            'ram_bytes' => $ram_bytes,
            'indexed_documents' => $indexed_documents
        ];
    }

    /**
     * Closes monitoring connection
     */
    public function close() {
        mysqli_close($this->connection);
    }
}
