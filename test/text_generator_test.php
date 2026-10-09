<?php
require_once __DIR__ . '/../src/text_generator.php';
require_once __DIR__ . '/../src/query_generator.php';

function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$tmp = tempnam(sys_get_temp_dir(), 'text-dict-');
file_put_contents($tmp, "the 1000\nsearch 100\ndatabase 20\nrare 1\n");
try {
    $opts = ['text-model' => 'realistic', 'text-dictionary' => $tmp, 'seed' => 42];
    $gen = new TextGenerator($opts);
    $counts = [];
    for ($i = 0; $i < 10000; $i++) {
        $word = strtolower(rtrim($gen->generate(1, 1), '.'));
        $counts[$word] = ($counts[$word] ?? 0) + 1;
    }
    check(($counts['the'] ?? 0) > ($counts['search'] ?? 0) * 5, 'Empirical frequencies not preserved');
    check(($counts['search'] ?? 0) > ($counts['database'] ?? 0) * 2, 'Medium-frequency term distribution incorrect');

    $a = new TextGenerator($opts);
    $b = new TextGenerator($opts);
    for ($i = 0; $i < 30; $i++) {
        check($a->generate(10, 100) === $b->generate(10, 100), 'Seed must reproduce text');
    }
    $opts['seed'] = 43;
    check((new TextGenerator($opts))->generate(50, 50) !== $a->generate(50, 50), 'Different seeds should differ');

    $real = new TextGenerator(['text-model' => 'realistic', 'text-dictionary' => $tmp, 'seed' => 5]);
    $sample = $real->generate(120, 150);
    check(count(preg_split('/\\s+/', trim($sample))) >= 120, 'Document too short');
    check(str_ends_with($sample, '.'), 'Text must end in a period');

    $old = TextGenerator::fingerprint($opts, ['<text/2/2>']);
    file_put_contents($tmp, "the 1000\nsearch 100\ndatabase 20\nrare 1\nnewword 3\n");
    check($old !== TextGenerator::fingerprint($opts, ['<text/2/2>']), 'Changed dictionary must invalidate cache');

    $autoCache = '/tmp/manticore-load-english-frequency.txt';
    $existing = is_file($autoCache) ? file_get_contents($autoCache) : null;
    try {
        file_put_contents($autoCache, "the 1000\nsearch 100\ndatabase 20\n");
        $auto = new TextGenerator(['text-model' => 'realistic', 'text-dictionary' => 'auto', 'seed' => 42]);
        $sample = $auto->generate(10, 10);
        check(count(explode(' ', $sample)) === 10, 'Auto cached vocabulary must generate ten words');
        $tokens = preg_split('/\\s+/', strtolower(trim($sample)));
        foreach ($tokens as $token) {
            check(in_array(rtrim($token, '.,'), ['the', 'search', 'database'], true),
                'Auto dictionary should use words from cached source');
        }
        check(TextGenerator::fingerprint(['text-dictionary' => 'auto'], ['<text/2/2>']) !== '',
            'Automatic dictionary should work without downloading during fingerprint');
    } finally {
        if ($existing === null) @unlink($autoCache);
        else file_put_contents($autoCache, $existing);
    }

    check((new TextGenerator(['text-model' => 'legacy']))->generate(3, 3) !== '',
        'Legacy text generation must remain available');
    echo "Text generator tests passed\n";
} finally {
    @unlink($tmp);
}
