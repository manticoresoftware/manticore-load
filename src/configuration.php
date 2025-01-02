<?php

/*
Copyright (c) Manticore Software Ltd.

This file is part of the manticore-load tool and is licensed under the MIT License.
For full license details, see the LICENSE file in the project root.

Source code available at: https://github.com/manticoresoftware/manticore-load
*/

/**
 * Configuration class that handles command-line arguments and process configurations
 * Implements ArrayAccess for array-like access to options
 */
class Configuration implements ArrayAccess {
    /** @var array Stores all configuration options */
    private $options = [];
    
    /** @var string Short command line options string */
    private $shortopts = 'h:p:vq';
    
    /** @var array Long command line options array */
    private $longopts = [
        'host:',
        'port:',
        'init:',
        'load:',
        'drop::',
        'batch-size:',
        'threads:',
        'total:',
        'iterations:',
        'verbose',
        'quiet',
        'wait',
        'no-color',
        'latency-histograms::',
        'help',
        'together',
        'column:',
        'delay:',
    ];
    
    /** @var array Default configuration values */
    private $defaults = [
        'host' => '127.0.0.1',
        'port' => 9306,
        'threads' => 1,
        'batch-size' => 1,
        'iterations' => 1,
        'drop' => false,
        'verbose' => false,
        'quiet' => false,
        'wait' => false,
        'no-color' => false,
        'latency-histograms' => true,
        'delay' => 0
    ];

    /** @var array Stores configurations for multiple processes */
    private $processes = [];
    
    /** @var array Current process configuration being built */
    private $current_process = [];

    /**
     * Constructor - initializes configuration from command line arguments
     * @param array|null $argv Command line arguments (uses global $argv if null)
     */
    public function __construct($argv = null) {
        if ($argv === null) {
            global $argv;
        }
        
        $options = $this->parseCommandLine($argv);
        $this->initializeOptions($options);
        $this->validate();
    }

    /**
     * Parses command line arguments into options and process configurations
     * Handles both short (-h) and long (--host) option formats
     * 
     * @param array $argv Command line arguments
     * @return array Parsed options
     */
    private function parseCommandLine($argv) {
        $options = [];
        $process_options = [];
        $per_process_params = [
            'drop', 'batch-size', 'threads', 'total', 
            'iterations', 'init', 'load', 'column', 'delay'
        ];
        $index = 1;
        
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            $key = null;
            $value = null;

            if (substr($arg, 0, 2) === '--') {
                $parts = explode('=', substr($arg, 2), 2);
                if (count($parts) === 2) {
                    list($key, $value) = $parts;
                    if (in_array($key . ':', $this->longopts) || in_array($key . '::', $this->longopts) || in_array($key, $this->longopts)) {
                        if ($key === 'together') {
                            if (!empty($process_options)) {
                                $this->processes[$index++] = $process_options;
                                $process_options = [];
                            }
                            continue;
                        }
                        if (in_array($key, $per_process_params)) {
                            $process_options[$key] = $value;
                        } else {
                            $options[$key] = $value;
                        }
                    }
                } elseif (in_array($parts[0] . '::', $this->longopts) || in_array($parts[0], $this->longopts)) {
                    $key = $parts[0];
                    if ($key === 'together') {
                        if (!empty($process_options)) {
                            $this->processes[$index++] = $process_options;
                            $process_options = [];
                        }
                        continue;
                    }
                    if (in_array($key, $per_process_params)) {
                        $process_options[$key] = true;
                    } else {
                        $options[$key] = true;
                    }
                }
            } elseif (substr($arg, 0, 1) === '-') {
                $key = substr($arg, 1, 1);
                if (strpos($this->shortopts, $key . ':') !== false) {
                    $value = $i + 1 < count($argv) ? $argv[++$i] : null;
                    $options[$key] = $value;
                } elseif (strpos($this->shortopts, $key) !== false) {
                    $options[$key] = true;
                }
            }
        }

        if (!empty($process_options)) {
            $this->processes[$index++] = $process_options;
        }

        if (isset($options['help'])) {
            $this->showUsage();
        }

        // If no processes defined, treat all process-specific options as a single process
        if (empty($this->processes)) {
            $process_options = [];
            foreach ($per_process_params as $param) {
                $param = str_replace('-', '_', $param);
                if (isset($options[$param])) {
                    $process_options[$param] = $options[$param];
                    unset($options[$param]);
                }
            }
            if (!empty($process_options)) {
                $this->processes[$index++] = $process_options;
            }
        }

        return $options;
    }

    /**
     * Initializes options by merging defaults with provided options
     * Also processes and normalizes configuration for each process
     * 
     * @param array $options Parsed command line options
     */
    private function initializeOptions($options) {
        $this->options = array_merge($this->defaults, $options);
        
        // Parse column option if present
        if (isset($this->options['column'])) {
            $parts = explode('/', $this->options['column'], 2);
            if (count($parts) === 2) {
                $this->options['column_name'] = $parts[0];
                $this->options['column_value'] = $parts[1];
            }
        }
        
        foreach ($this->processes as &$process) {
            $process = array_merge(
                $this->defaults,
                array_intersect_key($this->options, $this->defaults),
                $process
            );
            
            if (isset($process['load'])) {
                $process['load_command'] = $process['load'];
            }
            
            if (isset($process['init'])) {
                $process['init_command'] = $process['init'];
            }

            if (isset($process['drop'])) {
                $process['drop-table'] = true;
            }
            
            foreach (['port', 'total', 'iterations'] as $key) {
                if (isset($process[$key])) {
                    $process[$key] = (int)$process[$key];
                }
            }

            // Special handling for threads parameter to allow comma-separated values
            if (isset($process['threads'])) {
                if (strpos($process['threads'], ',') !== false) {
                    $thread_values = array_map('intval', explode(',', $process['threads']));
                    $process['threads'] = $thread_values;
                } else {
                    $process['threads'] = [(int)$process['threads']];
                }
            }

            // Special handling for batch-size parameter to allow comma-separated values
            if (isset($process['batch-size'])) {
                if (strpos($process['batch-size'], ',') !== false) {
                    $batch_values = array_map('intval', explode(',', $process['batch-size']));
                    $process['batch-size'] = $batch_values;
                } else {
                    $process['batch-size'] = [(int)$process['batch-size']];
                }
            }
        }
    }

    /**
     * Validates configuration parameters for all processes
     * Checks for required parameters and valid numeric values
     * 
     * @throws RuntimeException If validation fails
     */
    private function validate() {
        foreach ($this->processes as $index => $process) {
            $required = ['total', 'load'];

            if ($this->isInsertQuery($process['load_command'] ?? null)) {
                $required[] = 'batch-size';
            }

            foreach ($required as $param) {
                if (!isset($process[$param]) || $process[$param] === null) {
                    $arg_name = str_replace('_', '-', $param);
                    die("ERROR: Missing required parameter --$arg_name for process " . $index . "\n");
                }
            }

            $numeric = ['total', 'iterations'];
            foreach ($numeric as $param) {
                if (isset($process[$param]) && $process[$param] <= 0) {
                    $arg_name = str_replace('_', '-', $param);
                    die("ERROR: Parameter --$arg_name must be a positive number for process " . $index . "\n");
                }
            }

            // Validate threads array
            if (isset($process['threads'])) {
                foreach ($process['threads'] as $thread_count) {
                    if ($thread_count <= 0) {
                        die("ERROR: Thread count must be a positive number for process " . $index . "\n");
                    }
                }
            }

            // Validate batch-size array
            if (isset($process['batch-size'])) {
                foreach ($process['batch-size'] as $batch_size) {
                    if ($batch_size <= 0) {
                        die("ERROR: Batch size must be a positive number for process " . $index . "\n");
                    }
                }
            }
        }
    }

    /**
     * Returns array of all configured processes
     * @return array Array of process configurations
     */
    public function getProcesses() {
        return $this->processes;
    }

    /**
     * Checks if a given SQL command is an INSERT or REPLACE query
     * 
     * @param string|null $command SQL command to check
     * @return bool True if command is INSERT/REPLACE, false otherwise
     */
    public static function isInsertQuery($command = null) {
        if ($command === null) {
            return false;
        }
        $command = strtolower($command);
        return strpos($command, 'insert') === 0 || strpos($command, 'replace') === 0;
    }

    /**
     * Displays usage information and exits
     * 
     * @param string|null $error Optional error message to display
     */
    private function showUsage($error = null) {
        if ($error) {
            die("ERROR: $error\n");
        }
        
        die(
            "TEST Usage: ./manticore-load [options] [--together [options]...]\n\n" .
            "Required options:\n" .
            "  --threads=N                  Number of concurrent threads (single value or comma-separated list)\n" .
            "  --total=N                    For INSERT/REPLACE: total documents to generate\n" .
            "                               For SELECT/other: total queries to execute\n" .
            "  --load=SQL                   SQL command template for the main load\n" .
            
            "\nOptional options:\n" .
            "  --batch-size=N               Number of documents per batch (single value or comma-separated list)\n" .
            "                               Required for INSERT/REPLACE operations\n" .
            "  --iterations=N               Number of times to repeat the data generation (default: 1)\n" .
            "  --host=HOST                  Manticore host (default: 127.0.0.1)\n" .
            "  --port=PORT                  Manticore port (default: 9306)\n" .
            "  --init=SQL                   SQL command to execute before loading (e.g., CREATE TABLE)\n" .
            "  --drop                       Drop table if exists\n" .
            "  --verbose                    Show prepared queries before execution\n" .
            "  --latency-histograms=[0|1]   Use histogram-based latency tracking (default: 1)\n" .
            "                               1: memory-efficient but approximate percentiles\n" .
            "                               0: precise percentiles but higher memory usage\n" .
            "  --delay=N                    Add artificial delay between queries in seconds (default: 0)\n" .
            "  --together                   Run multiple processes with different configurations.\n" .
            "                               Each section after --together can have its own process-specific\n" .
            "                               options (threads, batch-size, load, etc). Global options\n" .
            "                               like host and port should be specified before the first --together\n" .
            "  --help                       Show this help message\n" .
            "  --column=NAME/VALUE           Add custom column in quiet mode output\n" .
            "                               Format: --column=name/value (e.g., batch/1000)\n" .
            
            "\nPattern formats in load command:\n" .
            "  value                        Exact value to use\n" .
            "  <increment>                  Auto-incrementing value starting from 1\n" .
            "  <increment/1000>             Auto-incrementing value starting from 1000\n" .
            "  <string/3/10>                Random string, length between 3 and 10\n" .
            "  <text/20/100>                Random text with 20 to 100 words\n" .
            "  <text/{/path/to/file}/10/100> Random text using words from file, 10 to 100 words\n" .
            "  <int/1/100>                  Random integer between 1 and 100\n" .
            "  <float/1/1000>               Random float between 1 and 1000\n" .
            "  <boolean>                    Random true or false\n" .
            "  <array/2/10/100/1000>        Array of 2-10 elements, values 100-1000\n" .
            "  <array_float/256/512/0/1>    Array of 256-512 random floats, values between 0 and 1\n\n" .
            
            "Examples:\n\n" .
            "# Load 1M documents in batches of 1000:\n" .
            "manticore-load \\\n" .
            "--batch-size=1000 \\\n" .
            "--threads=5 \\\n" .
            "--total=1000000 \\\n" .
            "--init=\"CREATE TABLE test(id bigint, name text, type int)\" \\\n" .
            "--load=\"INSERT INTO test(id,name,type) VALUES(<increment>,'<text/10/100>',<int/1/100>)\"\n\n" .
            
            "# Execute 1000 search queries:\n" .
            "manticore-load \\\n" .
            "--threads=5 \\\n" .
            "--total=10000 \\\n" .
            "--load=\"SELECT * FROM test WHERE MATCH('<text/1/1>')\"\n\n" .
           
            "# First process inserts data, second process runs queries simultaneously\n" .
            "manticore-load \\\n" .
            "--host=127.0.0.1 \\\n" .
            "--drop --batch-size=1000 --threads=4 --total=1000000 \\\n" .
            "--init=\"CREATE TABLE test(id bigint, name text, type int)\" \\\n" .
            "--load=\"INSERT INTO test(id,name,type) VALUES(<increment>,'<text/10/100>',<int/1/100>)\" \\\n" .
            "--together \\\n" .
            "--threads=1 --total=5000 \\\n" .
            "--load=\"SELECT * FROM test WHERE MATCH('<text/1/1>')\"\n\n"
        );
    }

    /**
     * Gets a configuration option value
     * 
     * @param string $key Option key
     * @return mixed Option value or null if not set
     */
    public function get($key) {
        return isset($this->options[$key]) ? $this->options[$key] : null;
    }

    /**
     * Sets a configuration option value
     * 
     * @param string $key Option key
     * @param mixed $value Option value
     */
    public function set($key, $value) {
        $this->options[$key] = $value;
    }

    /**
     * Magic getter for configuration options
     * 
     * @param string $key Option key
     * @return mixed Option value
     */
    public function __get($key) {
        return $this->get($key);
    }

    /**
     * Magic setter for configuration options
     * 
     * @param string $key Option key
     * @param mixed $value Option value
     */
    public function __set($key, $value) {
        $this->set($key, $value);
    }

    /**
     * Magic method to check if option exists
     * 
     * @param string $key Option key
     * @return bool True if option exists
     */
    public function __isset($key) {
        return isset($this->options[$key]);
    }

    /**
     * ArrayAccess interface implementation for setting values
     * 
     * @param mixed $offset Array key
     * @param mixed $value Array value
     */
    public function offsetSet(mixed $offset, mixed $value): void {
        $this->set($offset, $value);
    }

    /**
     * ArrayAccess interface implementation for checking if key exists
     * 
     * @param mixed $offset Array key
     * @return bool True if key exists
     */
    public function offsetExists(mixed $offset): bool {
        return isset($this->options[$offset]);
    }

    /**
     * ArrayAccess interface implementation for unsetting values
     * 
     * @param mixed $offset Array key
     */
    public function offsetUnset(mixed $offset): void {
        unset($this->options[$offset]);
    }

    /**
     * ArrayAccess interface implementation for getting values
     * 
     * @param mixed $offset Array key
     * @return mixed Value at offset
     */
    public function offsetGet(mixed $offset): mixed {
        return $this->get($offset);
    }

    /**
     * Add new method to extract table name from SQL query
     * 
     * @param string $sql SQL query to extract table name from
     * @return string Extracted table name
     */
    private function extractTableName($sql) {
        if (!$sql) {
            return '';
        }
        
        $sql = strtolower($sql);
        
        // For INSERT/REPLACE queries
        if (preg_match('/^(?:insert\s+into|replace\s+into)\s+([^\s(]+)/i', $sql, $matches)) {
            return $matches[1];
        }
        
        // For CREATE TABLE queries
        if (preg_match('/^create\s+table\s+([^\s(]+)/i', $sql, $matches)) {
            return $matches[1];
        }

        // For SELECT queries
        if (preg_match('/\bfrom\s+([^\s,()]+)/i', $sql, $matches)) {
            return $matches[1];
        }
                
        return '';
    }

    /**
     * Extracts operation type from SQL query
     * 
     * @param string $sql SQL query to analyze
     * @return string Operation type: 'select', 'insert', 'replace', 'delete', 'update', or 'other'
     */
    private function extractLoadType($sql) {
        if (!$sql) {
            return 'other';
        }
        
        $sql = trim(strtolower($sql));
        
        if (strpos($sql, 'select') === 0) {
            return 'select';
        }
        if (strpos($sql, 'insert') === 0) {
            return 'insert';
        }
        if (strpos($sql, 'replace') === 0) {
            return 'replace';
        }
        if (strpos($sql, 'delete') === 0) {
            return 'delete';
        }
        if (strpos($sql, 'update') === 0) {
            return 'update';
        }
        
        return 'other';
    }

    /**
     * Gets configuration for a specific process
     * 
     * @param int $index Process index
     * @return array|null Process configuration or null if not found
     */
    public function getProcessConfig($index) {
        if (!isset($this->processes[$index])) {
            return null;
        }
        
        $config = $this->processes[$index];
        
        // Extract table name from SQL queries
        $table = '';
        if (isset($config['load_command'])) {
            $table = $this->extractTableName($config['load_command']);
        }
        if (!$table && isset($config['init_command'])) {
            $table = $this->extractTableName($config['init_command']);
        }
        
        $config['table'] = $table;
        
        // Add operation type
        if (isset($config['load_command'])) {
            $config['load_type'] = $this->extractLoadType($config['load_command']);
        } else {
            $config['load_type'] = 'other';
        }
        
        return $config;
    }
}
