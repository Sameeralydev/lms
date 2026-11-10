<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.testing')->safeLoad();

$database = $_ENV['DB_DATABASE'] ?? '';
$username = $_ENV['DB_USERNAME'] ?? '';

if ($database !== 'techmentors_lms_test' || $username !== 'techmentors_test') {
    fwrite(STDERR, "FAIL: unexpected test database identity\n");
    exit(2);
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $username,
    $_ENV['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$identity = $pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
printf("Database=%s Account=%s MySQL=%s\n", $identity['db'], preg_replace('/@.*/', '', $identity['account']), $identity['version']);

$schemaGrant = false;
$unexpectedGlobal = false;

foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
    if (preg_match('/ON\s+`?\*`?\.\*/i', $grant)) {
        $unexpectedGlobal = ! preg_match('/GRANT\s+USAGE\s+ON/i', $grant);

        continue;
    }

    if (preg_match('/ON\s+`?techmentors_lms_test`?\.\*/i', $grant)) {
        $schemaGrant = true;

        continue;
    }

    if (preg_match('/ON\s+`?([^` .]+)`?\.\*/i', $grant, $matches) && $matches[1] !== 'information_schema') {
        fwrite(STDERR, "FAIL: unexpected schema grant\n");
        exit(3);
    }
}

if ($unexpectedGlobal) {
    fwrite(STDERR, "FAIL: non-USAGE global grant\n");
    exit(4);
}

printf("Dedicated schema grant present=%s; non-USAGE global grant=%s\n", $schemaGrant ? 'yes' : 'no', $unexpectedGlobal ? 'yes' : 'no');

$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
printf("Base tables=%d\n", count($tables));

foreach ($tables as [$table]) {
    $quoted = '`'.str_replace('`', '``', $table).'`';

    try {
        $count = $pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();
        printf("Table %s rows=%d\n", $table, $count);
    } catch (Throwable $exception) {
        printf("Table %s rows=UNREADABLE (%s)\n", $table, $exception::class);
    }
}

if (! $schemaGrant) {
    fwrite(STDERR, "BLOCKED: no dedicated schema grant; no writes performed\n");
    exit(5);
}
