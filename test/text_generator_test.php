<?php
require_once __DIR__ . '/../src/text_generator.php';
require_once __DIR__ . '/../src/query_generator.php';

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$tmp = tempnam(sys_get_temp_dir(), 'text-dict-');
file_put_contents($tmp, "the 1000\nsearch 100\ndatabase 20\nrare 1\n");
try {
    $opts = ['text-model' => 'zipf', 'text-dictionary' => $tmp, 'text-weighting' => 'empirical', 'seed' => 42];
    $gen = new TextGenerator($opts);
    $counts = [];
    for ($i = 0; $i < 10000; $i++) {
        $word = $gen->generate(1, 1, null, 'any');
        $counts[$word] = ($counts[$word] ?? 0) + 1;
    }
    check(($counts['the'] ?? 0) > ($counts['search'] ?? 0) * 5, 'Empirical sampling did not preserve frequency order');
    check(($counts['search'] ?? 0) > ($counts['database'] ?? 0) * 2, 'Medium-term frequencies incorrect');

    $a = new TextGenerator($opts);
    $b = new TextGenerator($opts);
    for ($i = 0; $i < 30; $i++) {
        check($a->generate(10, 100) === $b->generate(10, 100), 'Seed must reproduce text');
    }
    $opts['seed'] = 43;
    check((new TextGenerator($opts))->generate(50, 50) !== $a->generate(50, 50), 'Different seeds should produce different text');

    $real = new TextGenerator(['text-model' => 'realistic', 'text-dictionary' => $tmp, 'seed' => 5]);
    $sample = $real->generate(120, 150);
    check(count(preg_split('/\\s+/', trim($sample))) >= 120, 'Document too short');
    check(str_ends_with($sample, '.'), 'Text must end with a period');
    $q = $real->generate(2, 2, null, 'rare');
    check(!str_contains($q, '.') && !str_contains($q, ','), 'Query terms must not have punctuation');

    $old = TextGenerator::fingerprint($opts, ['<text/2/2>']);
    file_put_contents($tmp, "the 1000\nsearch 100\ndatabase 20\nrare 1\nnewword 3\n");
    check($old !== TextGenerator::fingerprint($opts, ['<text/2/2>']), 'Changed dictionaries must invalidate caches');

    // Prepopulate the /tmp cache: this checks the offline cache-hit path.
    $autoCache = '/tmp/manticore-load-english-frequency.txt';
    $existingCache = is_file($autoCache) ? file_get_contents($autoCache) : null;
    try {
        file_put_contents($autoCache, "the 1000\nsearch 100\ndatabase 20\n");
        $automatic = new TextGenerator([
            'text-model' => 'zipf', 'text-dictionary' => 'auto',
            'text-weighting' => 'empirical', 'seed' => 42,
        ]);
        $sample = $automatic->generate(10, 10, null, 'any');
        check(count(explode(' ', $sample)) === 10, 'Automatic cached dictionary must supply words');
        check((bool)preg_match('/^(the|search|database)( (the|search|database)){9}$/', $sample),
            'Automatic dictionary must use the cached frequency words');
        check(TextGenerator::fingerprint(['text-dictionary' => 'auto'], ['<text/2/2>']) !== '',
            'Automatic dictionary must be fingerprintable without a local input file');
    } finally {
        if ($existingCache === null) {
            @unlink($autoCache);
        } else {
            file_put_contents($autoCache, $existingCache);
        }
    }

    $text = (new TextGenerator(['text-model' => 'realistic', 'seed' => 42, 'text-heaps-scale' => 1]))->generate(100, 100);
    check(strlen($text) > 100, 'Built-in vocabulary must produce text');
    echo "Text generator tests passed\n";
} finally {
    @unlink($tmp);
}
