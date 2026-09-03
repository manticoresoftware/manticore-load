<?php

require_once __DIR__ . '/../src/progress_display.php';

function assertSameValue($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf(
            "FAIL: %s\nExpected: %s\nActual:   %s\n",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

function removeTree($path) {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
        removeTree($path . '/' . $entry);
    }
    rmdir($path);
}

$procRoot = sys_get_temp_dir() . '/manticore_load_proc_' . bin2hex(random_bytes(4));
mkdir($procRoot . '/net', 0777, true);

// Three searchd processes listen on the same port on different addresses.
// The first deliberately has a larger RSS so selecting by name or port alone fails.
file_put_contents(
    $procRoot . '/net/tcp',
    "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt uid timeout inode\n" .
    "   0: 0100007F:245A 00000000:0000 0A 00000000:00000000 00:00000000 00000000 1000 0 111\n" .
    "   1: 0200007F:245A 00000000:0000 0A 00000000:00000000 00:00000000 00000000 1000 0 222\n" .
    "   2: 0100000A:245A 00000000:0000 0A 00000000:00000000 00:00000000 00000000 1000 0 333\n"
);
file_put_contents(
    $procRoot . '/net/tcp6',
    "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt uid timeout inode\n" .
    "   0: 00000000000000000000000001000000:245A 00000000000000000000000000000000:0000 0A 00000000:00000000 00:00000000 00000000 1000 0 444\n"
);

foreach ([111 => [111, 8192], 222 => [222, 2048], 333 => [333, 4096], 444 => [444, 5120]] as $pid => [$inode, $rssKb]) {
    mkdir($procRoot . "/$pid/fd", 0777, true);
    file_put_contents($procRoot . "/$pid/comm", "searchd\n");
    file_put_contents($procRoot . "/$pid/status", "Name:\tsearchd\nVmRSS:\t{$rssKb} kB\n");
    symlink("socket:[$inode]", $procRoot . "/$pid/fd/7");
}

try {
    assertSameValue(
        '2MB',
        ProgressDisplay::getSearchdRssUsage('127.0.0.2', 9306, $procRoot),
        'RSS must come from the searchd listening on the target address and port'
    );

    file_put_contents($procRoot . '/222/status', "Name:\tsearchd\nVmRSS:\t3072 kB\n");
    assertSameValue(
        '3MB',
        ProgressDisplay::getSearchdRssUsage('127.0.0.2', 9306, $procRoot),
        'RSS must be refreshed when the cached searchd process grows'
    );

    unlink($procRoot . '/222/fd/7');
    symlink('socket:[999]', $procRoot . '/222/fd/7');
    assertSameValue(
        'N/A',
        ProgressDisplay::getSearchdRssUsage('127.0.0.2', 9306, $procRoot),
        'a cached PID must be discarded when it no longer owns the target listener'
    );

    assertSameValue(
        '4MB',
        ProgressDisplay::getSearchdRssUsage('10.0.0.1', 9306, $procRoot, ['10.0.0.1']),
        'a searchd bound to a non-loopback local interface must be supported'
    );

    assertSameValue(
        '5MB',
        ProgressDisplay::getSearchdRssUsage('::1', 9306, $procRoot),
        'an IPv6 listener must be matched by address and port'
    );

    assertSameValue(
        'N/A',
        ProgressDisplay::getSearchdRssUsage('192.0.2.10', 9306, $procRoot),
        'a remote endpoint must not be attributed to a local searchd'
    );

    $display = new ProgressDisplay(true, 1, true, false, null);
    $display->recordResourceStats('17%', '2MB', 1024);
    $display->recordResourceStats('9%', '3MB', 512);
    $display->recordResourceStats('N/A', 'N/A', 4096);
    assertSameValue(
        ['rss' => '3MB', 'disk' => '4KB', 'cpu' => '17%'],
        $display->getPeakResourceStats(),
        'resource peaks must retain the maximum valid sample for the final report'
    );
    $display->cleanup();
} finally {
    removeTree($procRoot);
}

echo "OK\n";
