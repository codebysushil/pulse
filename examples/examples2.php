<?php
require __DIR__ . '/vendor/autoload.php';

use Pulse\Promise;
use function Pulse\{async, await, delay, run};

/* ---------- Simulated non-blocking I/O ---------- */
function getUser(int $id): Promise {
    return delay(0.5)->then(fn() => ['id' => $id, 'name' => 'Ada']);
}
function getPosts(int $userId): Promise {
    return delay(0.7)->then(function () use ($userId) {
        if ($userId === 99) throw new RuntimeException("No posts for user 99");
        return ['Post 1', 'Post 2'];
    });
}
function getSettings(): Promise {
    return delay(0.4)->then(fn() => ['theme' => 'dark']);
}

/* ---------- Simulated BLOCKING I/O (same delays) ---------- */
function getUserSync(int $id): array   { usleep(500_000); return ['id' => $id, 'name' => 'Ada']; }
function getPostsSync(int $id): array  { usleep(700_000); return ['Post 1', 'Post 2']; }
function getSettingsSync(): array      { usleep(400_000); return ['theme' => 'dark']; }

function report(string $label, float $start, array $data): void {
    printf("%-26s %.2fs  -> %s\n", $label, microtime(true) - $start, json_encode($data));
}

/* =========================================================
 * 1) WITHOUT async: plain blocking code
 *    Everything waits for everything: 0.5 + 0.7 + 0.4
 * ========================================================= */
$t = microtime(true);
$user     = getUserSync(1);
$posts    = getPostsSync($user['id']);
$settings = getSettingsSync();
report('1) Blocking', $t, compact('user', 'posts', 'settings'));

/* =========================================================
 * 2) WITHOUT await: promise callbacks (then/catch)
 *    Concurrent, but nested and harder to read.
 * ========================================================= */
$t = microtime(true);
$data = await(
    Promise::all([
        getUser(1)->then(
            fn($user) => getPosts($user['id'])->then(
                fn($posts) => ['user' => $user, 'posts' => $posts]
            )
        ),
        getSettings(),
    ])->then(fn($r) => $r[0] + ['settings' => $r[1]])
);
report('2) Promise callbacks', $t, $data);

/* =========================================================
 * 3) WITH async/await: concurrent, reads top to bottom
 * ========================================================= */
run(function () {
    $t = microtime(true);

    $settingsP = getSettings();                 // start early, don't wait yet
    $user      = await(getUser(1));
    $posts     = await(getPosts($user['id']));
    $settings  = await($settingsP);             // already finished by now

    report('3) async/await', $t, compact('user', 'posts', 'settings'));

    /* ---------- Error handling compared ---------- */
    echo "\nError handling:\n";

    // Callback style: .catch() chained on the promise
    $msg = await(
        getUser(99)
            ->then(fn($u) => getPosts($u['id']))
            ->catch(fn($e) => 'callback catch: ' . $e->getMessage())
    );
    echo "  $msg\n";

    // async/await style: ordinary try/catch
    try {
        $u = await(getUser(99));
        await(getPosts($u['id']));
    } catch (RuntimeException $e) {
        echo "  try/catch:      {$e->getMessage()}\n";
    }
});
