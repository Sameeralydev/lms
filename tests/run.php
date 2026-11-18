<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$options = ['phase' => null, 'profile' => null, 'check' => 'all', 'report' => true];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(phase|profile|check)=(.+)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    } elseif ($argument === '--no-report') {
        $options['report'] = false;
    } else {
        fwrite(STDERR, "Usage: php tests/run.php --phase=01|04|...|NN|04v2|all --profile=sqlite|mysql [--check=all|security|concurrency] [--no-report]\n");
        exit(2);
    }
}

$phase = $options['phase'];
$profile = $options['profile'];
$check = $options['check'];
if (! is_string($phase) || ! is_string($profile) || ! in_array($profile, ['sqlite', 'mysql'], true)
    || ! in_array($check, ['all', 'security', 'concurrency'], true)
    || ($phase !== 'all' && ! preg_match('/^(0[1-9]|[1-9][0-9]|04v2)$/i', $phase))) {
    fwrite(STDERR, "Invalid phase, profile, or check selection.\n");
    exit(2);
}
if ($profile === 'mysql' && ($phase === 'all' || (int) $phase < 4)) {
    fwrite(STDERR, "Guarded MySQL execution is available only for individually provisioned phases 04-15; no database was contacted.\n");
    exit(2);
}
if ($profile === 'sqlite' && $phase !== 'all' && $check !== 'all') {
    fwrite(STDERR, "Category-only SQLite checks are global name filters; use --phase=all for security/concurrency filters or --check=all for the selected phase. No tests were run.\n");
    exit(2);
}
if ($profile === 'sqlite' && $check === 'concurrency') {
    fwrite(STDERR, "SQLite cannot verify independent database concurrency. Use an individually guarded MySQL phase runner; no concurrency result is claimed.\n");
    exit(2);
}

$php = PHP_BINARY;
$artisan = $root.DIRECTORY_SEPARATOR.'artisan';
$commands = [];
if ($profile === 'sqlite') {
    if ($phase === 'all') {
        $commands[] = $check === 'all'
            ? [$php, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'run-tests.php', '--sqlite']
            : [$php, $artisan, 'test', '--filter=(Security|Authorization|Guard|Isolation)'];
    } else {
        $phaseFilter = stripos($phase, '04v2') === 0 ? 'Phase04' : 'Phase'.(int) $phase;
        $commands[] = [$php, $artisan, 'test', '--filter='.$phaseFilter];
    }
} else {
    $number = (int) $phase;
    $normalizedPhase = strtolower($phase);
    $commands[] = match (true) {
        $normalizedPhase === '04v2' => [$php, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'run-tests.php', '--mysql-phase04-v2'],
        $number === 4 => [$php, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'run-tests.php', '--mysql-phase04'],
        $number === 5 => [$php, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'run-tests.php', '--mysql-phase05'],
        $number === 6 => [$php, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'run-tests.php', '--mysql-phase06'],
        $number === 7 => [$php, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'run-tests.php', '--mysql-phase07'],
        default => [$php, $root.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.sprintf('run-phase%02d-mysql.php', $number), '--run'],
    };
    if ($number >= 8 && ! is_file($commands[0][1])) {
        fwrite(STDERR, sprintf("No guarded MySQL runner exists for phase %s; no database was contacted.\n", $phase));
        exit(2);
    }
}

$started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$status = 0;
foreach ($commands as $command) {
    $display = implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
    fwrite(STDOUT, 'Central test runner: '.$display.PHP_EOL);
    $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        fwrite(STDERR, "Could not start selected test command.\n");
        $status = 2;
        break;
    }
    $status = proc_close($process);
    if ($status !== 0) {
        break;
    }
}
$finished = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($options['report']) {
    $reportPhase = stripos($phase, '04v2') === 0 ? '04' : strtolower($phase);
    $reportDirectory = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'phase-reports'.DIRECTORY_SEPARATOR.'phase-'.($phase === 'all' ? '00' : $reportPhase);
    if ($phase === 'all') {
        $reportDirectory = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'testing';
    }
    if (! is_dir($reportDirectory) && ! mkdir($reportDirectory, 0775, true) && ! is_dir($reportDirectory)) {
        fwrite(STDERR, "Tests executed; report directory could not be created.\n");
        exit($status === 0 ? 2 : $status);
    }
    $report = $reportDirectory.DIRECTORY_SEPARATOR.'CENTRAL_TEST_RUNS.md';
    $entry = sprintf("\n## %s — phase %s / %s / %s\n\n- Started (UTC): `%s`\n- Finished (UTC): `%s`\n- Command: `%s`\n- Exit code: `%d`\n- Evidence: console output from this invocation; no test outcome is inferred.\n", $finished->format('Y-m-d H:i:s'), $phase, $profile, $check, $started->format(DATE_ATOM), $finished->format(DATE_ATOM), $display, $status);
    file_put_contents($report, $entry, FILE_APPEND | LOCK_EX);
}
exit($status);
