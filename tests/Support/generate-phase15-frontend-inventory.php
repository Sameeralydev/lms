<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$root = dirname(__DIR__, 2);
$routes = [];
/** @var Route $route */
$routeCollection = app('router')->getRoutes();
foreach ($routeCollection->getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 't/{tenant}')) {
        continue;
    }

    $action = $route->getActionName();
    $controller = null;
    $method = null;
    $components = [];
    if ($action !== 'Closure' && str_contains($action, '@')) {
        [$controller, $method] = explode('@', $action, 2);
        if (class_exists($controller)) {
            $reflection = new ReflectionClass($controller);
            $source = file_get_contents($reflection->getFileName());
            if (is_string($source)) {
                preg_match_all("/Inertia::render\(['\"]([^'\"]+)['\"]/", $source, $matches);
                $components = array_values(array_unique($matches[1]));
            }
        }
    }

    $componentFiles = array_map(static fn (string $component): array => [
        'component' => $component,
        'file' => 'resources/js/pages/'.str_replace('.', '/', $component).'.tsx',
        'exists' => is_file($root.'/resources/js/pages/'.str_replace('.', '/', $component).'.tsx'),
    ], $components);

    $routes[] = [
        'name' => $route->getName(),
        'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
        'uri' => '/'.$route->uri(),
        'action' => $action,
        'controller' => $controller,
        'controller_method' => $method,
        'middleware' => $route->gatherMiddleware(),
        'inertia_components_in_controller' => $componentFiles,
        'frontend_status' => $componentFiles === [] ? 'non-inertia-or-delegated-response' : (collect($componentFiles)->every(fn (array $item): bool => $item['exists']) ? 'component-present' : 'component-missing'),
    ];
}

usort($routes, static fn (array $left, array $right): int => [$left['uri'], $left['methods']] <=> [$right['uri'], $right['methods']]);
$output = [
    'generated_at' => now()->toIso8601String(),
    'source' => 'Laravel runtime route collection; tenant route prefix t/{tenant}',
    'route_count' => count($routes),
    'routes' => $routes,
];
$path = $root.'/docs/phase-reports/phase-15/PHASE_15_FRONTEND_ROUTE_INVENTORY.json';
file_put_contents($path, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
fwrite(STDOUT, "Generated {$output['route_count']} tenant route records at {$path}".PHP_EOL);
