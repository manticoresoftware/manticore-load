<?php

/*
Copyright (c) Manticore Software Ltd.

This file is part of the manticore-load tool and is licensed under the MIT License.
For full license details, see the LICENSE file in the project root.

Source code available at: https://github.com/manticoresoftware/manticore-load
*/

/**
 * Class QueryGenerator
 * Generates SQL queries based on patterns and caches them for reuse
 */
class QueryGenerator {
    private $config;
    private $cache_file_name;
    private $load_info;
    private static $words = null;
    private static $words_count = null;
    private $process_index;
    private $stop_shm_id;

    /**
     * Constructor initializes the query generator with configuration and shared memory
     * @param Configuration $config Configuration object containing process settings
     */
    public function __construct(Configuration $config) {
        $this->config = $config;
        $this->process_index = $config->get('process_index');
        $this->load_info = $this->parseLoadCommand($config->get('load_command'));
        $this->cache_file_name = $this->generateCacheFileName();
        
        // Get the same shared memory segment
        $stop_shm_key = ftok(dirname(__FILE__) . '/../manticore-load', 'x');
        $this->stop_shm_id = shmop_open($stop_shm_key, "w", 0, 0);
    }
    
    /**
     * Generates a random string of specified length
     * @param int $min Minimum length of string
     * @param int $max Maximum length of string
     * @return string Random string of characters
     */
    public static function generateRandomString($min, $max) {
        static $chars = 'abcdefghijklmnopqrstuvwxyz';
        static $chars_len = 26;
        
        $length = rand($min, $max);
        $result = '';
        
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[rand(0, $chars_len - 1)];
        }
        
        return $result;
    }
    
    /**
     * Generates random text with proper sentence structure and punctuation
     * @param int $minWords Minimum number of words
     * @param int $maxWords Maximum number of words
     * @return string Generated text
     */
    public static function generateRandomText($minWords, $maxWords) {
        static $punctuation = array('.', '!', '?', ',', ';');
        
        // Initialize word list only once
        if (self::$words === null) {
            self::$words = array(
                'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have', 'I',
                'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you', 'do', 'at',
                'this', 'but', 'his', 'by', 'from', 'they', 'we', 'say', 'her', 'she',
                'would', 'could', 'should', 'will', 'may', 'might', 'must', 'shall', 'can', 'had',
                'has', 'was', 'were', 'been', 'being', 'am', 'is', 'are', 'does', 'did',
                'go', 'went', 'gone', 'see', 'saw', 'seen', 'take', 'took', 'taken', 'make',
                'made', 'find', 'found', 'get', 'got', 'give', 'gave', 'think', 'thought', 'know',
                'knew', 'come', 'came', 'tell', 'told', 'work', 'worked', 'call', 'called', 'try',
                'tried', 'ask', 'asked', 'need', 'needed', 'feel', 'felt', 'become', 'became', 'leave',
                'left', 'put', 'run', 'ran', 'bring', 'brought', 'begin', 'began', 'keep', 'kept',
                'hold', 'held', 'write', 'wrote', 'stand', 'stood', 'hear', 'heard', 'let', 'set',
                'meet', 'met', 'pay', 'paid', 'sit', 'sat', 'speak', 'spoke', 'lie', 'lay',
                'lead', 'led', 'read', 'grow', 'grew', 'lose', 'lost', 'fall', 'fell', 'send',
                'sent', 'build', 'built', 'understand', 'understood', 'draw', 'drew', 'break', 'broke', 'spend',
                'spent', 'cut', 'hurt', 'sell', 'sold', 'rise', 'rose', 'drive', 'drove', 'buy',
                'beautiful', 'happy', 'sad', 'angry', 'excited', 'tired', 'hungry', 'thirsty', 'cold', 'hot',
                'big', 'small', 'tall', 'short', 'fat', 'thin', 'old', 'young', 'rich', 'poor',
                'fast', 'slow', 'early', 'late', 'hard', 'soft', 'loud', 'quiet', 'clean', 'dirty',
                'dark', 'light', 'heavy', 'light', 'strong', 'weak', 'wet', 'dry', 'good', 'bad',
                'high', 'low', 'long', 'short', 'wide', 'narrow', 'deep', 'shallow', 'thick', 'thin',
                'smooth', 'rough', 'sharp', 'dull', 'sweet', 'sour', 'bitter', 'salty', 'fresh', 'stale',
                'new', 'old', 'modern', 'ancient', 'wild', 'tame', 'brave', 'afraid', 'proud', 'humble',
                'wise', 'foolish', 'clever', 'stupid', 'kind', 'cruel', 'gentle', 'rough', 'calm', 'angry',
                'busy', 'lazy', 'careful', 'careless', 'serious', 'funny', 'happy', 'sad', 'rich', 'poor',
                'healthy', 'sick', 'alive', 'dead', 'right', 'wrong', 'true', 'false', 'real', 'fake',
                'open', 'closed', 'empty', 'full', 'heavy', 'light', 'hard', 'soft', 'hot', 'cold',
                'summer', 'winter', 'spring', 'autumn', 'morning', 'evening', 'night', 'day', 'dawn', 'dusk',
                'north', 'south', 'east', 'west', 'up', 'down', 'left', 'right', 'front', 'back',
                'inside', 'outside', 'above', 'below', 'near', 'far', 'here', 'there', 'everywhere', 'nowhere',
                'always', 'never', 'sometimes', 'often', 'rarely', 'usually', 'now', 'then', 'soon', 'later',
                'today', 'tomorrow', 'yesterday', 'weekly', 'monthly', 'yearly', 'daily', 'nightly', 'hourly', 'instantly',
                'quickly', 'slowly', 'suddenly', 'gradually', 'carefully', 'carelessly', 'quietly', 'loudly', 'softly', 'harshly',
                'easily', 'hardly', 'simply', 'complexly', 'naturally', 'artificially', 'personally', 'professionally', 'publicly', 'privately',
                'legally', 'illegally', 'formally', 'informally', 'physically', 'mentally', 'emotionally', 'spiritually', 'socially', 'individually',
                'politically', 'economically', 'culturally', 'historically', 'scientifically', 'artistically', 'musically', 'technically', 'medically', 'educationally',
                'locally', 'globally', 'nationally', 'internationally', 'regionally', 'universally', 'specifically', 'generally', 'particularly', 'commonly',
                'normally', 'unusually', 'regularly', 'irregularly', 'frequently', 'infrequently', 'occasionally', 'constantly', 'permanently', 'temporarily',
                'actively', 'passively', 'positively', 'negatively', 'directly', 'indirectly', 'correctly', 'incorrectly', 'successfully', 'unsuccessfully',
                'fortunately', 'unfortunately', 'happily', 'unhappily', 'luckily', 'unluckily', 'surprisingly', 'expectedly', 'obviously', 'subtly',
                'definitely', 'possibly', 'probably', 'certainly', 'maybe', 'perhaps', 'surely', 'doubtfully', 'clearly', 'vaguely',
                '1', '2', '3', '4', '5', '10', '20', '50', '100', '1000'
            );        
        }
        
        $numWords = rand($minWords, $maxWords);
        $text = array();
        if (self::$words_count === null) {
            self::$words_count = count(self::$words);
        }
        
        for ($i = 0; $i < $numWords; $i++) {
            $word = self::$words[rand(0, self::$words_count - 1)];
            
            // Capitalize first word of sentence
            if ($i == 0 || (end($text) && substr(end($text), -1) === '.')) {
                $word = ucfirst($word);
            }
            
            // Add random punctuation (20% chance, but not for last word)
            if ($i !== $numWords - 1 && rand(1, 100) <= 20) {
                $word .= $punctuation[array_rand($punctuation)];
            }
            
            $text[] = $word;
        }
        
        // Ensure text ends with a period
        $lastWord = &$text[count($text) - 1];
        if (!in_array(substr($lastWord, -1), $punctuation)) {
            $lastWord .= '.';
        }
        
        return implode(' ', $text);
    }
    
    /**
     * Parses a pattern string into a structured array defining data generation rules
     * @param string $pattern Pattern string (e.g. "increment/1" or "string/5/10")
     * @return array Pattern definition array
     * @throws Exception If pattern format is invalid
     */
    public static function parsePattern($pattern) {
        $parts = explode('/', $pattern);
        $type = $parts[0];
        
        // If it's not a known type, treat it as an exact value
        if (!in_array($type, ['increment', 'string', 'text', 'int', 'float', 'boolean', 'array'])) {
            return [
                'type' => 'exact',
                'value' => $pattern
            ];
        }
        
        switch ($type) {
            case 'increment':
                return [
                    'type' => 'increment',
                    'start' => isset($parts[1]) ? (int)$parts[1] : 1
                ];
                
            case 'string':
                if (count($parts) !== 3) {
                    throw new Exception("String pattern requires format: string/min_length/max_length");
                }
                return [
                    'type' => 'string',
                    'min_length' => (int)$parts[1],
                    'max_length' => (int)$parts[2]
                ];
                
            case 'text':
                if (count($parts) !== 3) {
                    throw new Exception("Text pattern requires format: text/min_words/max_words");
                }
                return [
                    'type' => 'text',
                    'min_words' => (int)$parts[1],
                    'max_words' => (int)$parts[2]
                ];
                
            case 'int':
            case 'bigint':
                if (count($parts) !== 3) {
                    throw new Exception("Int pattern requires format: int/min/max");
                }
                return [
                    'type' => 'int',
                    'min' => (int)$parts[1],
                    'max' => (int)$parts[2]
                ];
                
            case 'float':
                if (count($parts) !== 3) {
                    throw new Exception("Float pattern requires format: float/min/max");
                }
                return [
                    'type' => 'float',
                    'min' => (float)$parts[1],
                    'max' => (float)$parts[2],
                    'decimals' => 1
                ];
                
            case 'boolean':
                return ['type' => 'boolean'];
                
            case 'array':
                if (count($parts) !== 5) {
                    throw new Exception("Array pattern requires format: array/min_size/max_size/min_value/max_value");
                }
                return [
                    'type' => 'array',
                    'min_size' => (int)$parts[1],
                    'max_size' => (int)$parts[2],
                    'min_value' => (int)$parts[3],
                    'max_value' => (int)$parts[4]
                ];
                
            default:
                throw new Exception("Unknown pattern type: $type");
        }
    }
    
    /**
     * Generates a value based on the given pattern definition
     * @param array $pattern Pattern definition array
     * @return mixed Generated value
     * @throws Exception If pattern format is invalid
     */
    private function generateValue($pattern) {
        if (!is_array($pattern) || !isset($pattern['type'])) {
            throw new Exception("Invalid pattern format");
        }
    
        switch ($pattern['type']) {
            case 'exact':
                return "'" . addslashes($pattern['value']) . "'";
                
            case 'increment':
                static $counters = [];
                $key = json_encode($pattern);
                if (!isset($counters[$key])) {
                    $counters[$key] = $pattern['start'] - 1;
                }
                return ++$counters[$key];
                
            case 'string':
                return self::generateRandomString(
                    $pattern['min_length'] ?? 3,
                    $pattern['max_length'] ?? 10
                );
                
            case 'text':
                return self::generateRandomText(
                    $pattern['min_words'] ?? 20,
                    $pattern['max_words'] ?? 300
                );
                
            case 'int':
                return rand($pattern['min'] ?? 0, $pattern['max'] ?? PHP_INT_MAX);
                
            case 'float':
                $scale = pow(10, $pattern['decimals'] ?? 1);
                return round(rand($pattern['min'] * $scale ?? 0, $pattern['max'] * $scale ?? PHP_INT_MAX) / $scale, 
                            $pattern['decimals'] ?? 1);
                
            case 'boolean':
                return rand(0, 1) ? 'true' : 'false';
                
            case 'array':
                $size = rand($pattern['min_size'], $pattern['max_size']);
                return '(' . implode(',', array_map(function() use ($pattern) {
                    return rand($pattern['min_value'], $pattern['max_value']);
                }, range(1, $size))) . ')';
                
            default:
                throw new Exception("Unknown field type '{$pattern['type']}'");
        }
    }
    
    /**
     * Generates a unique cache filename based on configuration parameters
     * @return string Cache file path
     */
    private function generateCacheFileName() {
        // Create a unique cache key based on all relevant parameters
        $cache_key = implode('_', [
            $this->config->get('init_command'),
            $this->config->get('load_command'),
            $this->config->get('total'),
            $this->config->get('batch-size'),
            $this->config->get('process_index')
        ]);
        
        return '/tmp/manticore_load_' . md5($cache_key);
    }
    
    /**
     * Main entry point for query generation. Either loads from cache or generates new queries
     * @param bool $quiet If true, suppresses progress output
     * @return array Array of generated queries
     */
    public function generateQueries($quiet = false) {
        if (!file_exists($this->cache_file_name)) {
            return $this->generateAndCacheQueries($quiet);
        } else {
            if (!$quiet) ConsoleOutput::writeLine("Process {$this->process_index}: Using cached data from: {$this->cache_file_name}");
            return $this->loadQueriesFromCache();
        }        
    }
    
    /**
     * Loads previously cached queries from file
     * @return array Array of queries read from cache
     * @throws Exception If cache file cannot be read
     */
    private function loadQueriesFromCache() {
        $batches = file($this->cache_file_name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($batches === false) {
            throw new Exception("Error: Cannot read cache file");
        }
        return $batches;
    }
    
    /**
     * Generates queries and saves them to cache file
     * @param bool $quiet If true, suppresses progress output
     * @return array Array of generated queries
     * @throws Exception If cache file cannot be created
     */
    private function generateAndCacheQueries($quiet) {
        $cache_file = fopen($this->cache_file_name, 'w');
        if (!$cache_file) {
            throw new Exception("ERROR: Cannot create cache file");
        }
        
        $c = 0;
        $batch = [];
        $base_query = null;
        $batch_size = $this->load_info['is_batch_compatible'] ? $this->config->get('batch-size') : 1;
        $queries = [];
        
        while ($c < $this->config->get('total')) {
            // Check stop flag in shared memory
            $stop_requested = ord(shmop_read($this->stop_shm_id, 0, 1)) === 1;
            
            if ($stop_requested) {
                fclose($cache_file);
                unlink($this->cache_file_name);
                if (!$quiet) {
                    ConsoleOutput::write(sprintf("\r%-80s\r", ""));
                    ConsoleOutput::writeLine("Process {$this->process_index}: Cache generation interrupted.");
                }
                return [];
            }

            $query = $this->generateSingleQuery();
            
            if ($this->load_info['is_batch_compatible'] && $batch_size > 1) {
                if ($base_query === null) {
                    if (preg_match('/(.*VALUES\s*)\((.*)\)/i', $query, $matches)) {
                        $base_query = $matches[1];
                        $batch[] = "(" . $matches[2] . ")";
                    }
                } else {
                    if (preg_match('/VALUES\s*\((.*)\)/i', $query, $matches)) {
                        $batch[] = "(" . $matches[1] . ")";
                    }
                }
                
                if (count($batch) == $batch_size) {
                    $full_query = $base_query . implode(",", $batch);
                    fwrite($cache_file, $full_query . "\n");
                    $queries[] = $full_query;
                    $batch = [];
                }
            } else {
                fwrite($cache_file, $query . "\n");
                $queries[] = $query;
            }
            
            $c++;
            if ($c % 1000 == 0 && !$quiet) {
                $progress = sprintf("\r%-80s\r", "");
                $progress .= sprintf("Process {$this->process_index}: Generating new data cache... %d%%", 
                    round($c * 100 / $this->config->get('total'))
                );
                ConsoleOutput::write($progress);
            }
        }
        
        if (!empty($batch)) {
            $full_query = $base_query . implode(",", $batch);
            fwrite($cache_file, $full_query . "\n");
            $queries[] = $full_query;
        }
        
        fclose($cache_file);
        if (!$quiet) {
            ConsoleOutput::write(sprintf("\r%-80s\r", ""));
            ConsoleOutput::writeLine(sprintf("Process {$this->process_index}: Generating new data cache... 100%%"));
        }
        
        return $queries;
    }
    
    /**
     * Generates a single query by replacing patterns with generated values
     * @return string Generated SQL query
     */
    private function generateSingleQuery() {
        $query = $this->load_info['command'];
        foreach ($this->load_info['patterns'] as $pattern_text => $pattern) {
            $value = $this->generateValue($pattern);
            $query = str_replace("<$pattern_text>", $value, $query);
        }
        return $query;
    }
    
    /**
     * Parses the load command to extract patterns and determine batch compatibility
     * @param string $command SQL command template
     * @return array Command info including patterns and batch compatibility
     */
    private function parseLoadCommand($command) {
        $patterns = [];
        if (preg_match_all('/<([^>]+)>/', $command, $matches)) {
            foreach ($matches[1] as $match) {
                $patterns[$match] = self::parsePattern($match);
            }
        }

        $is_insert = (stripos($command, 'insert') === 0 || stripos($command, 'replace') === 0);

        return [
            'command' => $command,
            'patterns' => $patterns,
            'is_batch_compatible' => $is_insert
        ];
    }
}