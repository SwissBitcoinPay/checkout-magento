<?php
/**
 * Another request on an order, in a process of its own (ConcurrencyTest): it takes the order's
 * lock as the module does (Magento's database lock, GET_LOCK), waits until the test's request
 * waits for it, holds it $hold seconds more, stores its attempt (when given one), and lets go.
 *
 *   php other_request.php <host> <port> <database> <user> <password> <lock> <hold> [<attempt JSON>]
 *
 * It prints "locked" once it holds the lock, and "done" once it let go.
 */

declare(strict_types=1);

// $argv: PHP's CLI always defines it (PHPStan only knows it when register_argc_argv is on).
$args = $argv ?? [];
[, $host, $port, $database, $user, $password, $lock, $hold] = $args;
$attempt = isset($args[8]) ? json_decode($args[8], true) : null;
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database}", $user, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$locked = $pdo->prepare('SELECT GET_LOCK(?, 10)');
$locked->execute([$lock]);
if ((int) $locked->fetchColumn() !== 1) {
    fwrite(STDERR, "Could not take the lock\n");
    exit(1);
}
echo "locked\n";
// Until another connection waits for this lock.
$waiting = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE 'SELECT GET_LOCK(%'"
);
$deadline = microtime(true) + 60;
do {
    usleep(20000);
    $waiting->execute();
} while ((int) $waiting->fetchColumn() === 0 && microtime(true) < $deadline);
usleep((int) ((float) $hold * 1000000));
if (is_array($attempt)) {
    $columns = array_keys($attempt);
    $insert = $pdo->prepare(
        'INSERT INTO sattleme_payment_attempt (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')'
    );
    $insert->execute(array_values($attempt));
}
$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
echo "done\n";
