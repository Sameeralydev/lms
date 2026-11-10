import { spawn } from 'node:child_process';
import process from 'node:process';

const php = process.env.PHP_BINARY || 'php';
const children = [
    spawn(php, ['artisan', 'serve', '--host=127.0.0.1', '--port=8017'], {
        env: process.env,
        stdio: 'inherit',
    }),
    spawn(
        php,
        ['artisan', 'queue:work', 'database', '--sleep=1', '--tries=3', '--timeout=180', '--max-time=3600'],
        { env: process.env, stdio: 'inherit' },
    ),
];

let stopping = false;
const stopChildTree = (child) => {
    if (child.exitCode !== null || child.signalCode !== null || !child.pid) return;

    if (process.platform === 'win32') {
        // `artisan serve` launches PHP's built-in server through a Windows
        // command wrapper. Killing only the wrapper leaves the listener alive
        // and makes the next Playwright run attach to a stale test server.
        const killer = spawn('taskkill', ['/PID', String(child.pid), '/T', '/F'], {
            stdio: 'ignore',
            windowsHide: true,
        });
        killer.on('error', () => child.kill());
        return;
    }

    child.kill('SIGTERM');
};

const stop = (exitCode = 0) => {
    if (stopping) return;
    stopping = true;
    process.exitCode = exitCode;
    for (const child of children) stopChildTree(child);
};

for (const child of children) {
    child.on('error', (error) => {
        process.stderr.write('E2E PHP process failed to start: ' + error.message + '\n');
        stop(1);
    });
    child.on('exit', (code, signal) => {
        if (!stopping) stop(code ?? (signal ? 1 : 0));
    });
}

process.on('SIGINT', () => stop(0));
process.on('SIGTERM', () => stop(0));
