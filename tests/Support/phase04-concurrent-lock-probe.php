<?php

use Tests\Support\Phase06MySqlTestTarget;
use Tests\Support\Phase06ProbeAuthorization;
use Tests\Support\Phase07MySqlTestTarget;
use Tests\Support\Phase07ProbeAuthorization;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$allowedTargets = [
    'techmentors_lms_phase04_test' => 'techmentors_phase04_test',
    'techmentors_lms_phase04_v2_test' => 'techmentors_phase04_v2',
    'techmentors_lms_phase05_test' => 'techmentors_phase05_test',
    'techmentors_lms_phase06_test' => 'techmentors_phase06_test',
    Phase07MySqlTestTarget::DATABASE => Phase07MySqlTestTarget::ACCOUNT,
];

$database = getenv('DB_DATABASE') ?: '';
$username = getenv('DB_USERNAME') ?: '';
$host = getenv('DB_HOST') ?: '';
$port = getenv('DB_PORT') ?: '';
$password = getenv('DB_PASSWORD') ?: '';
$intakeId = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 0;
$signalName = $argv[2] ?? '';
$signalPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.$signalName;

if (
    getenv('APP_ENV') !== 'testing'
    || strtolower(getenv('DB_CONNECTION') ?: '') !== 'mysql'
    || (getenv('DB_URL') ?: '') !== ''
    || $host !== '127.0.0.1'
    || $port !== '3306'
    || $password === ''
    || $intakeId < 1
    || ! preg_match('/^phase0[4567]-row-lock-[0-9a-f-]{36}\.ready$/i', $signalName)
    || file_exists($signalPath)
    || is_link($signalPath)
    || ($allowedTargets[$database] ?? null) !== $username
) {
    fwrite(STDERR, "Probe refused an unapproved test target.\n");
    exit(2);
}

try {
    $connection = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ],
    );

    $identity = $connection->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_account')->fetch();
    $authenticatedUsername = explode('@', (string) ($identity['authenticated_account'] ?? ''), 2)[0];

    if (($identity['database_name'] ?? null) !== $database || $authenticatedUsername !== $username) {
        throw new RuntimeException('The probe connection identity did not match the allowlisted target.');
    }

    if ($database === Phase06MySqlTestTarget::DATABASE) {
        Phase06ProbeAuthorization::assertAuthorized($connection, TestDatabaseGuard::currentEnvironment());
    }
    if ($database === Phase07MySqlTestTarget::DATABASE) {
        Phase07ProbeAuthorization::assertAuthorized($connection, TestDatabaseGuard::currentEnvironment());
    }

    $connection->exec('SET SESSION innodb_lock_wait_timeout = 5');
    if (file_put_contents($signalPath, 'ready', LOCK_EX) !== 5) {
        throw new RuntimeException('Could not create the private readiness signal.');
    }
    $statement = $connection->prepare('SELECT id FROM migrations WHERE id = :id FOR UPDATE');
    $statement->bindValue(':id', $intakeId, PDO::PARAM_INT);
    $statement->execute();
    fwrite(STDOUT, $statement->fetchColumn() === false ? "rows=0\n" : "rows=1\n");
} catch (Throwable $exception) {
    $driverError = $exception instanceof PDOException ? (string) ($exception->errorInfo[1] ?? '') : '';
    fwrite(STDERR, sprintf("The isolated row-lock probe failed (type=%s; code=%s; driver=%s).\n", get_class($exception), (string) $exception->getCode(), $driverError));
    exit(2);
}

exit(0);
