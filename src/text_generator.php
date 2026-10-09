<?php
/*
Copyright (c) Manticore Software Ltd.
This file is part of manticore-load and is licensed under the MIT License.
*/

class TextRandom {
    private $state;

    public function __construct($seed) {
        $this->state = ((int)$seed) & 0xffffffff;
    }

    public function nextFloat() {
        $this->state = ($this->state * 1664525 + 1013904223) & 0xffffffff;
        return $this->state / 4294967296.0;
    }

    public function nextInt($max) {
        if ($max <= 0) {
            throw new InvalidArgumentException('Random bound must be positive');
        }
        return (int)floor($this->nextFloat() * $max);
    }

    public function between($min, $max) {
        return $min + $this->nextInt($max - $min + 1);
    }
}

/**
 * Vose alias sampler. Preprocessing is O(V); each draw is O(1).
 * Samples words using their observed frequencies.
 */
class TextWeightedSampler {
    private $words;
    private $prob = [];
    private $alias = [];

    public function __construct(array $words, array $weights) {
        $count = count($words);
        if (!$count || $count !== count($weights)) {
            throw new InvalidArgumentException('Sampler requires nonempty words and matching weights');
        }
        $this->words = array_values($words);
        $total = array_sum($weights);
        if (!is_finite((float)$total) || $total <= 0) {
            throw new InvalidArgumentException('Sampler weights must have a positive finite total');
        }

        $small = [];
        $large = [];
        foreach (array_values($weights) as $i => $weight) {
            $scaled = max(0.0, (float)$weight) * $count / $total;
            $this->prob[$i] = $scaled;
            $this->alias[$i] = $i;
            if ($scaled < 1.0) {
                $small[] = $i;
            } else {
                $large[] = $i;
            }
        }
        while ($small && $large) {
            $s = array_pop($small);
            $l = array_pop($large);
            $this->alias[$s] = $l;
            $this->prob[$l] = ($this->prob[$l] + $this->prob[$s]) - 1.0;
            if ($this->prob[$l] < 1.0) {
                $small[] = $l;
            } else {
                $large[] = $l;
            }
        }
        foreach (array_merge($small, $large) as $i) {
            $this->prob[$i] = 1.0;
        }
    }

    public function sample(TextRandom $rng) {
        $i = $rng->nextInt(count($this->words));
        $pick = $rng->nextFloat() < $this->prob[$i] ? $i : $this->alias[$i];
        return $this->words[$pick];
    }
}

/**
 * Opt-in realistic text: empirical frequencies, repetition and document lengths.
 * Uniform word selection is available through the default text model.
 */
class TextGenerator {
    private $config;
    private $mode;
    private $random;
    private $seed;
    private $vocabularies = [];

    public function __construct($config = []) {
        $get = function ($key, $default) use ($config) {
            return is_array($config) ? ($config[$key] ?? $default) : ($config->get($key) ?? $default);
        };
        $this->mode = $get('text-model', 'legacy');
        if (!in_array($this->mode, ['legacy', 'realistic'], true)) {
            throw new InvalidArgumentException('--text-model must be legacy or realistic');
        }
        $this->config = ['text-dictionary' => $get('text-dictionary', null)];
        $this->seed = (int)$get('seed', 42) + ((int)$get('process_index', 1) - 1) * 9973;
        $this->reseedWorker(0);
    }

    public function reseedWorker($worker) {
        $this->random = new TextRandom($this->seed + (int)$worker * 104729);
    }

    public static function fingerprint($config, array $commands) {
        $get = function ($key) use ($config) {
            return is_array($config) ? ($config[$key] ?? null) : $config->get($key);
        };
        $options = ['version' => 3, 'model' => $get('text-model'), 'dictionary' => $get('text-dictionary'), 'seed' => $get('seed')];
        $paths = [];
        if ($options['dictionary'] && $options['dictionary'] !== 'auto') {
            $paths[] = $options['dictionary'];
        }
        $usesDefaultText = false;
        foreach ($commands as $command) {
            if (preg_match('/<text\\/\\d+\\/\\d+>/', $command)) {
                $usesDefaultText = true;
            }
            if (preg_match_all('/text\\/\\{([^}]+)\\}/', $command, $matches)) {
                array_push($paths, ...$matches[1]);
            }
        }
        if (($options['model'] ?? 'legacy') === 'realistic' && $usesDefaultText &&
            ($options['dictionary'] === null || $options['dictionary'] === 'auto')) {
            $paths[] = self::automaticDictionary();
        }
        foreach (array_unique($paths) as $path) {
            if (!is_readable($path)) {
                throw new RuntimeException("Cannot read text dictionary: $path");
            }
            $options['files'][$path] = hash_file('sha256', $path);
        }
        return hash('sha256', json_encode($options));
    }

    private static function automaticDictionary() {
        $path = '/tmp/manticore-load-english-frequency.txt';
        if (is_readable($path) && filesize($path) > 0) return $path;
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock dictionary cache');
        try {
            clearstatcache(true, $path);
            if (is_readable($path) && filesize($path) > 0) return $path;
            // Override the source in offline tests or private environments.
            $url = getenv('MANTICORE_LOAD_DICTIONARY_URL') ?:
                'https://raw.githubusercontent.com/hackerb9/gwordlist/5e9902468ab09802474884c3df00d77463e5cb24/frequency-alpha-alldicts.txt';
            $source = @fopen($url, 'rb');
            if (!$source) throw new RuntimeException('Cannot download English frequency dictionary');
            $tmp = tempnam('/tmp', 'manticore-dict-');
            if ($tmp === false) {
                fclose($source);
                throw new RuntimeException('Cannot create temporary dictionary');
            }
            $out = @fopen($tmp, 'wb');
            if (!$out) {
                fclose($source);
                @unlink($tmp);
                throw new RuntimeException('Cannot write temporary dictionary');
            }
            $count = 0;
            try {
                while (($line = fgets($source)) !== false) {
                    if (preg_match('/^\\s*#?(\\d+)\\s+([a-zA-Z]+)\\s+([\\d,]+)/', $line, $m)) {
                        $weight = str_replace(',', '', $m[3]);
                        if ((float)$weight > 0) {
                            if (fwrite($out, strtolower($m[2]) . ' ' . $weight . "\n") === false) {
                                throw new RuntimeException('Cannot write dictionary cache');
                            }
                            $count++;
                        }
                    }
                }
            } catch (Throwable $error) {
                @unlink($tmp);
                throw $error;
            } finally {
                fclose($source);
                fclose($out);
            }
            if (!$count || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Downloaded dictionary contains no usable words');
            }
            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function generate($minWords, $maxWords, $file = null) {
        $minWords = (int)$minWords;
        $maxWords = (int)$maxWords;
        if ($minWords < 0 || $maxWords < $minWords) {
            throw new InvalidArgumentException('Invalid text word count bounds');
        }
        if ($maxWords === 0) return '';
        $path = $file ?? $this->config['text-dictionary'];
        if ($this->mode === 'legacy') {
            return QueryGenerator::generateRandomText($minWords, $maxWords, $path);
        }
        if ($path === null || $path === 'auto') $path = self::automaticDictionary();
        $sampler = $this->sampler($path);
        $length = $this->documentLength($minWords, $maxWords);
        $words = [];
        $reusable = [];
        $sentence = $this->random->between(8, 20);
        $capitalize = true;
        for ($i = 0; $i < $length; $i++) {
            // Repeat content words in the same document to reproduce burstiness.
            if ($reusable && $this->random->nextFloat() < 0.12) {
                $word = $reusable[$this->random->nextInt(count($reusable))];
            } else {
                $word = $sampler->sample($this->random);
            }
            if ($i > 0 && strlen($word) > 3) {
                $reusable[] = $word;
                if (count($reusable) > 200) array_shift($reusable);
            }
            if ($capitalize) $word = ucfirst($word);
            $capitalize = false;
            $sentence--;
            if ($sentence <= 0 || $i === $length - 1) {
                $word .= '.';
                $capitalize = true;
                $sentence = $this->random->between(8, 20);
            } elseif ($sentence > 3 && $this->random->nextFloat() < 0.05) {
                $word .= ',';
            }
            $words[] = $word;
        }
        return implode(' ', $words);
    }

    private function documentLength($min, $max) {
        if ($min === $max) return $min;
        if ($max === 0) return 0;
        $low = max(1, $min);
        $median = sqrt($low * $max);
        $sigma = max(0.1, log(max(1.01, $max / $low)) / 3);
        $u = max($this->random->nextFloat(), 1e-12);
        $z = sqrt(-2 * log($u)) * cos(2 * M_PI * $this->random->nextFloat());
        return max($min, min($max, (int)round($median * exp($sigma * $z))));
    }

    private function sampler($path) {
        $key = realpath($path) ?: $path;
        if (isset($this->vocabularies[$key])) return $this->vocabularies[$key];
        $handle = @fopen($path, 'r');
        if (!$handle) throw new RuntimeException("Cannot read dictionary: $path");
        $counts = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^([\\p{L}\\p{N}_-]+)\\s+([0-9]+(?:\\.[0-9]+)?)$/u', $line, $m)) {
                $word = strtolower($m[1]);
                $counts[$word] = ($counts[$word] ?? 0) + (float)$m[2];
            } elseif (preg_match('/^[\\p{L}\\p{N}_-]+$/u', $line)) {
                $word = strtolower($line);
                $counts[$word] = ($counts[$word] ?? 0) + 1;
            }
        }
        fclose($handle);
        if (!$counts) throw new RuntimeException("Empty dictionary: $path");
        return $this->vocabularies[$key] = new TextWeightedSampler(array_keys($counts), array_values($counts));
    }
}
