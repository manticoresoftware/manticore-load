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
 * Works with empirical counts and with rank-based Zipf weights.
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
 * Statistical text generation. Legacy behavior remains selectable.
 *
 * A finite ranked dictionary has a finite vocabulary. Realistic mode
 * optionally introduces rare compound tokens with Heaps-like vocabulary
 * growth; these tokens are synthetic and should not be used for semantic
 * relevance evaluations.
 */
class TextGenerator {
    private $config;
    private $random;
    private $mode;
    private $seed;
    private $vocabularies = [];
    private $tokenCount = 0;
    private $rareCounter = 0;
    private $recentRare = [];
    private $worker = 0;

    private const TOPIC_WORDS = [
        'technology' => 'software database query search index server network computer code memory storage algorithm system data technology digital internet',
        'finance' => 'market stock investment financial money revenue profit company bank business economy trade cash loan tax',
        'science' => 'science research scientific experiment physics chemistry theory laboratory discovery space energy particle',
        'travel' => 'travel journey city hotel booking flight train tourist airport country beach road map destination',
        'health' => 'health doctor medical patient hospital medicine disease treatment nurse body exercise fitness',
        'sports' => 'sport team player football tennis game match score victory league tournament coach race',
        'nature' => 'nature forest river mountain ocean water weather animal earth tree climate environment plant',
        'education' => 'education school university student teacher study lesson course learning knowledge exam book',
    ];

    public function __construct($config = []) {
        $this->config = [];
        foreach ([
            'text-model' => 'legacy', 'text-dictionary' => null,
            'text-weighting' => 'auto', 'zipf-exponent' => 1.0,
            'zipf-shift' => 2.7, 'text-burstiness' => 0.12,
            'text-length' => 'auto', 'text-topic-strength' => 1.0,
            'text-topics-file' => null, 'text-heaps-beta' => 0.55,
            'text-heaps-scale' => 0.65, 'seed' => 42,
            'process_index' => 1,
        ] as $key => $default) {
            $value = is_array($config) ? ($config[$key] ?? $default) : ($config->get($key) ?? $default);
            $this->config[$key] = $value;
        }
        $this->mode = (string)$this->config['text-model'];
        if (!in_array($this->mode, ['legacy', 'zipf', 'realistic'], true)) {
            throw new InvalidArgumentException('--text-model must be legacy, zipf, or realistic');
        }
        if (!in_array($this->config['text-weighting'], ['auto', 'zipf', 'empirical'], true)) {
            throw new InvalidArgumentException('--text-weighting must be auto, zipf, or empirical');
        }
        if (!in_array($this->config['text-length'], ['auto', 'uniform', 'lognormal'], true)) {
            throw new InvalidArgumentException('--text-length must be auto, uniform, or lognormal');
        }
        foreach (['text-burstiness', 'text-topic-strength'] as $key) {
            if (!is_numeric($this->config[$key]) || $this->config[$key] < 0 || $this->config[$key] > 1) {
                throw new InvalidArgumentException("--$key must be between 0 and 1");
            }
        }
        if ($this->config['zipf-exponent'] <= 0 || $this->config['zipf-shift'] < 0 ||
            $this->config['text-heaps-beta'] <= 0 || $this->config['text-heaps-beta'] >= 1 ||
            $this->config['text-heaps-scale'] < 0) {
            throw new InvalidArgumentException('Invalid Zipf or Heaps parameters');
        }
        $this->seed = (int)$this->config['seed'] + ((int)$this->config['process_index'] - 1) * 9973;
        $this->reseedWorker(0);
    }

    public function reseedWorker($worker) {
        $this->worker = (int)$worker;
        $this->random = new TextRandom($this->seed + $this->worker * 104729);
        $this->tokenCount = 0;
        $this->rareCounter = 0;
        $this->recentRare = [];
    }

    public static function fingerprint($config, array $commands) {
        $keys = [
            'text-model', 'text-dictionary', 'text-weighting', 'zipf-exponent',
            'zipf-shift', 'text-burstiness', 'text-length', 'text-topic-strength',
            'text-topics-file', 'text-heaps-beta', 'text-heaps-scale', 'seed'
        ];
        $options = ['version' => 1];
        foreach ($keys as $key) {
            $options[$key] = is_array($config) ? ($config[$key] ?? null) : $config->get($key);
        }
        $paths = [];
        foreach ([$options['text-dictionary'], $options['text-topics-file']] as $path) {
            if ($path && $path !== 'auto') {
                $paths[] = $path;
            }
        }
        foreach ($commands as $command) {
            if (preg_match_all('/text(?:_query)?\\/\\{([^}]+)\\}/', $command, $matches)) {
                array_push($paths, ...$matches[1]);
            }
        }
        foreach (array_unique($paths) as $path) {
            if (!is_readable($path)) {
                throw new RuntimeException("Cannot read text dictionary/topic file: $path");
            }
            $options['files'][$path] = hash_file('sha256', $path);
        }
        return hash('sha256', json_encode($options));
    }

    private static function automaticDictionary() {
        $path = '/tmp/manticore-load-english-frequency.txt';
        if (is_readable($path) && filesize($path) > 0) return $path;
        $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock dictionary cache');
        try {
            if (is_readable($path) && filesize($path) > 0) return $path;
            $source = 'https://raw.githubusercontent.com/hackerb9/gwordlist/master/frequency-alpha-alldicts.txt';
            $data = @file_get_contents($source);
            if ($data === false) throw new RuntimeException('Cannot download English frequency dictionary');
            $rows = [];
            foreach (explode("\n", $data) as $line) {
                if (preg_match('/^\\s*#?(\\d+)\\s+([a-zA-Z]+)\\s+([\\d,]+)/', $line, $m)) {
                    $rows[] = strtolower($m[2]) . ' ' . str_replace(',', '', $m[3]);
                }
            }
            if (!$rows) throw new RuntimeException('Empty downloaded dictionary');
            $temporary = tempnam('/tmp', 'manticore-dict-');
            file_put_contents($temporary, implode("\n", $rows) . "\n");
            rename($temporary, $path);
            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param string|null $file Override dictionary from --text-dictionary.
     * @param string|null $queryTier common|medium|rare to generate plain MATCH terms.
     */
    public function generate($minWords, $maxWords, $file = null, $queryTier = null) {
        $minWords = (int)$minWords;
        $maxWords = (int)$maxWords;
        if ($minWords < 0 || $maxWords < $minWords) {
            throw new InvalidArgumentException('Invalid text word count bounds');
        }
        $path = $file ?? $this->config['text-dictionary'];
        if ($path === 'auto') $path = self::automaticDictionary();
        if ($this->mode === 'legacy' && $queryTier === null) {
            return QueryGenerator::generateRandomText($minWords, $maxWords, $path);
        }
        $vocab = $this->vocabulary($path);
        $len = $this->wordCount($minWords, $maxWords, $queryTier !== null);
        if ($len === 0) {
            return '';
        }

        $topic = null;
        if ($queryTier === null && $this->mode === 'realistic' && $vocab['topicChoice'] !== null) {
            $topic = $vocab['topicChoice']->sample($this->random);
        }
        $text = [];
        $content = [];
        $sentenceLeft = $this->random->between(8, 20);
        $capitalize = true;
        for ($i = 0; $i < $len; $i++) {
            $this->tokenCount++;
            if ($queryTier !== null) {
                $word = $vocab['query'][$queryTier]->sample($this->random);
            } elseif ($this->mode === 'realistic' && $content &&
                $this->random->nextFloat() < (float)$this->config['text-burstiness']) {
                $word = $content[$this->random->nextInt(count($content))];
            } elseif ($this->mode === 'realistic' && $this->shouldIntroduceRare()) {
                $word = $this->newRareWord($vocab['words']);
            } elseif ($this->mode === 'realistic' && $topic !== null &&
                $this->random->nextFloat() < $vocab['topicMass'] * (float)$this->config['text-topic-strength']) {
                $word = $vocab['topics'][$topic]->sample($this->random);
            } elseif ($this->mode === 'realistic' && $topic !== null && $vocab['nonTopic'] !== null) {
                $word = $vocab['nonTopic']->sample($this->random);
            } else {
                $word = $vocab['global']->sample($this->random);
            }

            if ($queryTier === null) {
                // Burstiness is applied primarily to meaningful words, not stopwords.
                if (!isset($vocab['stopwords'][strtolower($word)])) {
                    $content[] = $word;
                    if (count($content) > 400) {
                        array_shift($content);
                    }
                }
                if ($capitalize) {
                    $word = ucfirst($word);
                    $capitalize = false;
                }
                $sentenceLeft--;
                if ($sentenceLeft <= 0 || $i === $len - 1) {
                    $word .= $i === $len - 1 ? '.' : ($this->random->nextFloat() < 0.08 ? '?' : '.');
                    $capitalize = true;
                    $sentenceLeft = $this->random->between(8, 20);
                } elseif ($sentenceLeft > 3 && $this->random->nextFloat() < 0.055) {
                    $word .= ',';
                }
            }
            $text[] = $word;
        }
        return implode(' ', $text);
    }

    private function wordCount($min, $max, $query) {
        if ($min === $max) {
            return $min;
        }
        $mode = $this->config['text-length'];
        if ($query || $mode === 'uniform' || ($mode === 'auto' && $this->mode !== 'realistic')) {
            return $this->random->between($min, $max);
        }
        // Truncated lognormal, with the geometric mean as median.
        $low = max(1, $min);
        $median = sqrt($low * max($low, $max));
        $sigma = max(0.1, log(max(1.01, $max / $low)) / 3.0);
        $u = max($this->random->nextFloat(), 1e-12);
        $z = sqrt(-2.0 * log($u)) * cos(2 * M_PI * $this->random->nextFloat());
        return max($min, min($max, (int)round($median * exp($sigma * $z))));
    }

    private function shouldIntroduceRare() {
        $scale = (float)$this->config['text-heaps-scale'];
        if ($scale == 0) {
            return false;
        }
        $beta = (float)$this->config['text-heaps-beta'];
        $p = min(0.06, $scale * $beta * pow(max(1, $this->tokenCount), $beta - 1.0));
        return $this->random->nextFloat() < $p;
    }

    private function newRareWord(array $words) {
        // Compound + alphabetic suffix: one searchable token, unique within a worker.
        // Avoid punctuation and digits which can be split by tokenizers.
        $n = count($words);
        $a = preg_replace('/[^a-z]/', '', strtolower($words[$this->random->between(max(0, $n - 100), $n - 1)]));
        $b = preg_replace('/[^a-z]/', '', strtolower($words[$this->random->between(max(0, $n - 100), $n - 1)]));
        $number = ++$this->rareCounter + $this->worker * 1000000000;
        $suffix = '';
        do {
            $suffix = chr(97 + $number % 26) . $suffix;
            $number = intdiv($number, 26);
        } while ($number > 0);
        return ($a ?: 'word') . ($b ?: 'text') . $suffix;
    }

    private function vocabulary($path) {
        $key = $path === null ? ':builtin:' : (realpath($path) ?: $path);
        if (isset($this->vocabularies[$key])) {
            return $this->vocabularies[$key];
        }
        [$words, $empirical] = $path === null
            ? [array_values(array_unique(array_map('strtolower', QueryGenerator::builtinVocabulary()))), null]
            : $this->loadVocabulary($path);

        $count = count($words);
        $weights = [];
        $weightMode = $this->config['text-weighting'];
        if ($weightMode === 'empirical' && $empirical === null) {
            throw new InvalidArgumentException('--text-weighting=empirical requires word counts or source text');
        }
        for ($i = 0; $i < $count; $i++) {
            $weights[] = ($empirical !== null && $weightMode !== 'zipf')
                ? (float)$empirical[$i]
                : pow($i + 1 + (float)$this->config['zipf-shift'], -(float)$this->config['zipf-exponent']);
        }
        // When counts are supplied, sort by empirical frequency for query ranks.
        if ($empirical !== null && $weightMode !== 'zipf') {
            array_multisort($weights, SORT_DESC, SORT_NUMERIC, $words);
        }

        $v = [
            'words' => $words, 'global' => new TextWeightedSampler($words, $weights),
            'query' => [], 'topics' => [], 'topicMass' => 0.0,
            'topicChoice' => null, 'nonTopic' => null,
            'stopwords' => array_fill_keys(array_slice($words, 0, min(45, $count)), true),
        ];
        foreach (['common', 'medium', 'rare'] as $tier) {
            $start = $tier === 'common' ? 0 : ($tier === 'medium' ? max(1, (int)floor($count * 0.01)) : max(2, (int)floor($count * 0.20)));
            $end = $tier === 'common' ? max(1, (int)floor($count * 0.01)) : ($tier === 'medium' ? max(2, (int)floor($count * 0.20)) : $count);
            $start = min($start, $count - 1);
            $end = max($start + 1, min($end, $count));
            $v['query'][$tier] = new TextWeightedSampler(array_slice($words, $start, $end - $start), array_slice($weights, $start, $end - $start));
        }
        $v['query']['any'] = $v['global'];

        if ($this->mode === 'realistic' && $count > 10) {
            $topicMap = $this->topicMap();
            $names = array_keys(self::TOPIC_WORDS);
            $topicWords = array_fill_keys($names, []);
            $topicWeights = array_fill_keys($names, []);
            $topicMass = [];
            $otherWords = [];
            $otherWeights = [];
            $totalWeight = array_sum($weights);
            foreach ($words as $i => $word) {
                // Frequent function words belong to the non-topical background.
                if ($i < 45) {
                    $otherWords[] = $word;
                    $otherWeights[] = $weights[$i];
                    continue;
                }
                $topic = $topicMap[$word] ?? $names[(int)(sprintf('%u', crc32($word)) % count($names))];
                $topicWords[$topic][] = $word;
                $topicWeights[$topic][] = $weights[$i];
            }
            foreach ($names as $topic) {
                if ($topicWords[$topic]) {
                    $w = array_sum($topicWeights[$topic]);
                    $topicMass[$topic] = $w;
                    $v['topics'][$topic] = new TextWeightedSampler($topicWords[$topic], $topicWeights[$topic]);
                }
            }
            $v['topicMass'] = array_sum($topicMass) / $totalWeight;
            if ($topicMass && $otherWords) {
                $v['topicChoice'] = new TextWeightedSampler(array_keys($topicMass), array_values($topicMass));
                $v['nonTopic'] = new TextWeightedSampler($otherWords, $otherWeights);
            }
        }
        return $this->vocabularies[$key] = $v;
    }

    private function topicMap() {
        $map = [];
        foreach (self::TOPIC_WORDS as $topic => $list) {
            foreach (explode(' ', $list) as $word) {
                $map[$word] = $topic;
            }
        }
        $path = $this->config['text-topics-file'];
        if ($path !== null && $path !== '') {
            if (!is_readable($path)) {
                throw new RuntimeException("Cannot read text topic file: $path");
            }
            $file = fopen($path, 'r');
            while (($line = fgets($file)) !== false) {
                $parts = preg_split('/\\s+/', trim($line));
                if (count($parts) === 2 && isset(self::TOPIC_WORDS[$parts[0]])) {
                    $map[strtolower($parts[1])] = $parts[0];
                }
            }
            fclose($file);
        }
        return $map;
    }

    private function loadVocabulary($path) {
        $handle = @fopen($path, 'r');
        if (!$handle) {
            throw new RuntimeException("Cannot read dictionary: $path");
        }
        $lines = [];
        $format = null;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^([\\p{L}\\p{N}_-]+)\\s+([0-9]+(?:\\.[0-9]+)?)$/u', $line, $m)) {
                $type = 'counts';
            } elseif (preg_match('/^[\\p{L}\\p{N}_-]+$/u', $line)) {
                $type = 'ranked';
            } else {
                $type = 'corpus';
            }
            if ($format !== null && $type !== $format) {
                $format = 'corpus';
            } elseif ($format === null) {
                $format = $type;
            }
            $lines[] = $line;
        }
        fclose($handle);
        if (!$lines) {
            throw new RuntimeException("Empty text dictionary: $path");
        }

        $counts = [];
        if ($format === 'ranked') {
            $words = [];
            foreach ($lines as $line) {
                $word = strtolower($line);
                $words[$word] = $word;
            }
            return [array_values($words), null];
        }
        if ($format === 'counts') {
            foreach ($lines as $line) {
                if (!preg_match('/^(.+?)\\s+([0-9]+(?:\\.[0-9]+)?)$/u', $line, $m)) {
                    continue;
                }
                $word = strtolower($m[1]);
                $counts[$word] = ($counts[$word] ?? 0) + (float)$m[2];
            }
        } else {
            foreach ($lines as $line) {
                foreach (preg_split('/[^\\p{L}\\p{N}]+/u', strtolower($line), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $counts[$word] = ($counts[$word] ?? 0) + 1;
                }
            }
        }
        arsort($counts, SORT_NUMERIC);
        if (!$counts || array_sum($counts) <= 0) {
            throw new RuntimeException("Dictionary contains no positive weights: $path");
        }
        return [array_keys($counts), array_values($counts)];
    }
}
