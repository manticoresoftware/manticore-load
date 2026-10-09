<?php
require_once __DIR__ . '/../src/text_generator.php';
require_once __DIR__ . '/../src/query_generator.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$tmp = tempnam(sys_get_temp_dir(), 'text-dict-');
file_put_contents($tmp, "the 1000\nsearch 100\ndatabase 20\nrare 1\n");
try {
    $opts = ['realistic' => true];
    $gen = new TextGenerator($opts);
    $counts = [];
    for ($i = 0; $i < 10000; $i++) {
        $word = strtolower(rtrim($gen->generate(1, 1, $tmp), '.'));
        $counts[$word] = ($counts[$word] ?? 0) + 1;
    }
    check(($counts['the'] ?? 0) > ($counts['search'] ?? 0) * 5, 'Empirical frequencies not preserved');
    check(($counts['search'] ?? 0) > ($counts['database'] ?? 0) * 2, 'Medium-frequency term distribution incorrect');

    $a = new TextGenerator($opts);
    $b = new TextGenerator($opts);
    for ($i = 0; $i < 30; $i++) {
        check($a->generate(10, 100, $tmp) === $b->generate(10, 100, $tmp), 'Fixed seed must reproduce text');
    }

    $real = new TextGenerator(['realistic' => true]);
    $sample = $real->generate(120, 150, $tmp);
    check(count(preg_split('/\\s+/', trim($sample))) >= 120, 'Document too short');
    check(str_ends_with($sample, '.'), 'Text must end in a period');

    $old = TextGenerator::fingerprint($opts, ['<text/{' . $tmp . '}/2/2>']);
    file_put_contents($tmp, "the 1000\nsearch 100\ndatabase 20\nrare 1\nnewword 3\n");
    check($old !== TextGenerator::fingerprint($opts, ['<text/{' . $tmp . '}/2/2>']), 'Changed dictionary must invalidate cache');

    // Exercise first-run download using a local fixture in the upstream format.
    $autoCache = '/tmp/manticore-load-english-frequency.txt';
    $existing = is_file($autoCache) ? file_get_contents($autoCache) : null;
    $fixture = tempnam(sys_get_temp_dir(), 'gwordlist-');
    $originalUrl = getenv('MANTICORE_LOAD_DICTIONARY_URL');
    try {
        @unlink($autoCache);
        file_put_contents($fixture,
            "#RANKING WORD COUNT PERCENT CUMULATIVE\n" .
            "1 the 1,000 50% 50%\n" .
            "2 search 100 5% 55%\n" .
            "3 database 20 1% 56%\n"
        );
        putenv('MANTICORE_LOAD_DICTIONARY_URL=file://' . $fixture);
        $auto = new TextGenerator(['realistic' => true]);
        $sample = $auto->generate(10, 10);
        check(is_file($autoCache), 'Automatic dictionary must be cached in /tmp');
        check(str_contains(file_get_contents($autoCache), "the 1000\n"), 'Dictionary download must parse upstream counts');
        check(count(explode(' ', $sample)) === 10, 'Downloaded vocabulary must generate ten words');

        // The cache works offline and its contents affect generated-query cache keys.
        putenv('MANTICORE_LOAD_DICTIONARY_URL=file:///missing-fixture');
        $tokens = preg_split('/\s+/', strtolower($auto->generate(10, 10)));
        foreach ($tokens as $token) {
            check(in_array(rtrim($token, '.,'), ['the', 'search', 'database'], true),
                'Automatic dictionary must use cached words when offline');
        }
        $cacheOpts = ['realistic' => true];
        $key1 = TextGenerator::fingerprint($cacheOpts, ['<text/2/2>']);
        file_put_contents($autoCache, "the 1000\nsearch 100\ndatabase 20\nextra 3\n");
        $key2 = TextGenerator::fingerprint($cacheOpts, ['<text/2/2>']);
        check($key1 !== $key2, 'Changed cached dictionary must invalidate generated-query cache');
        check($key2 === TextGenerator::fingerprint($cacheOpts, ['<text/2/2>']),
            'Cached dictionary fingerprint must be stable');
        check($key2 !== TextGenerator::fingerprint(['realistic' => false], ['<text/2/2>']),
            'Different text models must have different cache keys');

        // Failed/invalid downloads must leave no cache and allow retry.
        @unlink($autoCache);
        file_put_contents($fixture, "this is not a frequency list\n");
        putenv('MANTICORE_LOAD_DICTIONARY_URL=file://' . $fixture);
        $failed = false;
        try {
            (new TextGenerator(['realistic' => true]))->generate(1, 1);
        } catch (RuntimeException $e) {
            $failed = true;
        }
        check($failed && !is_file($autoCache), 'Invalid download must not leave a cache');
    } finally {
        @unlink($fixture);
        if ($originalUrl === false) putenv('MANTICORE_LOAD_DICTIONARY_URL');
        else putenv('MANTICORE_LOAD_DICTIONARY_URL=' . $originalUrl);
        if ($existing === null) @unlink($autoCache);
        else file_put_contents($autoCache, $existing);
    }

    $caps = (new TextGenerator([
        'realistic' => true,
    ]))->generate(300, 300, $tmp);
    check((bool)preg_match('/\.\s+[A-Z]/', $caps), 'Words after sentence boundaries must be capitalized');
    check((new TextGenerator(['realistic' => true]))->generate(0, 0) === '',
        'Zero-length documents must not require a dictionary download');
    // Long documents exercise the bounded repetition buffer.
    $long = (new TextGenerator(['realistic' => true]))
        ->generate(1000, 1000, $tmp);
    check(count(explode(' ', $long)) === 1000, 'Long documents must keep the exact requested length');

    // Length distribution should be substantially skewed rather than uniform.
    $lengthGenerator = new TextGenerator(['realistic' => true]);
    $totalWords = 0;
    for ($i = 0; $i < 250; $i++) {
        $length = count(explode(' ', $lengthGenerator->generate(10, 1000, $tmp)));
        check($length >= 10 && $length <= 1000, 'Lognormal length must stay inside bounds');
        $totalWords += $length;
    }
    check($totalWords / 250 < 400, 'Lognormal lengths must favor shorter documents');

    // Frequency tiers follow ranks after sorting by empirical frequency.
    $tiers = tempnam(sys_get_temp_dir(), 'text-tiers-');
    $tierLines = [];
    for ($i = 0; $i < 100; $i++) {
        $tierLines[] = sprintf("word%03d %d", $i, 1000 - $i * 9);
    }
    file_put_contents($tiers, implode("\n", $tierLines) . "\n");
    try {
        $tierGenerator = new TextGenerator(['realistic' => true]);
        foreach (['common' => [0, 1], 'medium' => [1, 20], 'rare' => [20, 100]] as $tier => $range) {
            for ($j = 0; $j < 100; $j++) {
                $query = $tierGenerator->generate(1, 3, $tiers, $tier);
                $tokens = explode(' ', $query);
                check(count($tokens) >= 1 && count($tokens) <= 3, 'Invalid query length for ' . $tier);
                foreach ($tokens as $token) {
                    check((bool)preg_match('/^word[0-9]{3}$/', $token), 'Query terms must be plain tokens');
                    $rank = (int)substr($token, 4);
                    check($rank >= $range[0] && $rank < $range[1], 'Incorrect frequency tier: ' . $tier);
                }
            }
        }
        check(QueryGenerator::parsePattern('text/rare/1/3')['frequency_tier'] === 'rare',
            'Tier pattern must be parsed');
        foreach (['text/rare/no/3', 'text/rare/4/1', 'text/unknown/1/2'] as $bad) {
            $failed = false;
            try { QueryGenerator::parsePattern($bad); } catch (Exception $e) { $failed = true; }
            check($failed, 'Invalid frequency pattern must fail: ' . $bad);
        }
        $failed = false;
        try { (new TextGenerator(['realistic' => false]))->generate(1, 1, $tiers, 'common'); }
        catch (InvalidArgumentException $e) { $failed = true; }
        check($failed, 'Tier queries require --realistic');
    } finally {
        @unlink($tiers);
    }

    // Optional live smoke test, for checking the pinned upstream dictionary.
    // Intentionally not part of the default offline test suite.
    if (getenv('MANTICORE_LOAD_LIVE_DICTIONARY_TEST') === '1') {
        $path = '/tmp/manticore-load-english-frequency.txt';
        $original = is_file($path) ? file_get_contents($path) : null;
        $url = getenv('MANTICORE_LOAD_DICTIONARY_URL');
        try {
            putenv('MANTICORE_LOAD_DICTIONARY_URL');
            @unlink($path);
            $start = microtime(true);
            $live = new TextGenerator(['realistic' => true]);
            $text = $live->generate(100, 100);
            check(is_file($path), 'Live download must create a dictionary');
            $contents = file_get_contents($path);
            $lines = substr_count($contents, "\n");
            check($lines >= 200000, "Expected 200k+ words from upstream; got $lines");
            check(count(explode(' ', $text)) === 100, 'Live dictionary must produce 100 words');
            echo sprintf("Live dictionary: %d words, %.2fs, peak RAM %.1f MiB\n",
                $lines, microtime(true) - $start, memory_get_peak_usage(true) / 1048576);
        } finally {
            if ($url !== false) putenv('MANTICORE_LOAD_DICTIONARY_URL=' . $url);
            else putenv('MANTICORE_LOAD_DICTIONARY_URL');
            if ($original === null) @unlink($path);
            else file_put_contents($path, $original);
        }
    }

    check((new TextGenerator(['realistic' => false]))->generate(3, 3) !== '',
        'Legacy text generation must remain available');
    echo "Text generator tests passed\n";
} finally {
    @unlink($tmp);
}
