<?php
require __DIR__ . '/vendor/autoload.php';

use function Pulse\{async, await, delay, gather, timeout, run, fetch};

run(function () {
    $start = microtime(true);

    // 1. Three "slow" tasks run concurrently: total ≈ 1s, not 3s
    $results = await(gather([
        'a' => function () { await(delay(1.0)); return 'A done'; },
        'b' => function () { await(delay(0.5)); return 'B done'; },
        'c' => function () { await(delay(0.8)); return 'C done'; },
    ]));
    print_r($results);
    printf("Elapsed: %.2fs\n", microtime(true) - $start);

    // 2. Error handling works with plain try/catch
    try {
        await(async(function () {
            await(delay(0.1));
            throw new RuntimeException('boom');
        }));
    } catch (RuntimeException $e) {
        echo "Caught: {$e->getMessage()}\n";
    }

    // 3. Timeouts
    try {
        await(timeout(delay(2), 0.3));
    } catch (RuntimeException $e) {
        echo $e->getMessage(), "\n";
    }

    // 4. Concurrent HTTP
    [$one, $two] = await(gather([
        fetch('http://example.com/'),
        fetch('http://example.org/'),
    ]));
    echo strlen($one['body']), ' and ', strlen($two['body']), " bytes\n";
});
