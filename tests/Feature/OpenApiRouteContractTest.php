<?php

use Illuminate\Support\Facades\Route;

it('advertises only API operations that the current router exposes', function () {
    $normalize = fn (string $path) => '/'.ltrim(preg_replace('/\{[^}]+\}/', '{}', $path), '/');
    $actual = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $actual[$method.' '.$normalize($route->uri())] = true;
        }
    }
    $spec = json_decode(file_get_contents(base_path('openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];
    foreach ($spec['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            if (! in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
                continue;
            }
            if (! isset($actual[strtoupper($method).' '.$normalize($path)])) {
                $missing[] = strtoupper($method).' '.$path;
            }
        }
    }
    expect($missing)->toBe([]);
});
