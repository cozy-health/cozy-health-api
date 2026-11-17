<?php

use App\Http\Requests\JournalEntryRequest;
use App\Http\Requests\MoodEntryRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

it('documents every application API operation and keeps exclusions exact and current', function () {
    $normalize = fn (string $path) => '/'.ltrim(preg_replace('/\{[^}]+\}/', '{}', $path), '/');
    $actual = [];
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $actual[$method.' '.$normalize($route->uri())] = true;
        }
    }
    $spec = json_decode(file_get_contents(base_path('openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
    $documented = [];
    foreach ($spec['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            if (in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                $documented[strtoupper($method).' '.$normalize($path)] = true;
            }
        }
    }
    $exclusions = json_decode(file_get_contents(base_path('docs/API_OPERATION_EXCLUSIONS.json')), true, flags: JSON_THROW_ON_ERROR);
    $excluded = [];
    foreach ($exclusions as $entry) {
        expect($entry['reason'])->toBeString()->not->toBeEmpty();
        $key = $entry['method'].' '.$normalize($entry['path']);
        expect(isset($actual[$key]))->toBeTrue('Stale exclusion: '.$key);
        expect(isset($documented[$key]))->toBeFalse('Documented operations must not be excluded: '.$key);
        expect(isset($excluded[$key]))->toBeFalse('Duplicate exclusion: '.$key);
        $excluded[$key] = true;
    }
    expect(array_values(array_diff(array_keys($actual), array_keys($documented), array_keys($excluded))))->toBe([]);
});

it('keeps the operation ledger and saved requests aligned with the documented router', function () {
    $spec = json_decode(file_get_contents(base_path('openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
    $ledger = json_decode(file_get_contents(base_path('docs/API_OPERATION_COVERAGE.json')), true, flags: JSON_THROW_ON_ERROR);
    $normalize = fn (string $path) => preg_replace('/\{[^}]+\}/', '{}', $path);
    $documented = [];
    $ids = [];
    foreach ($spec['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            if (! in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
                continue;
            }
            $id = $operation['operationId'];
            expect(isset($ids[$id]))->toBeFalse('Duplicate operation ID: '.$id);
            expect($operation['responses'])->not->toBeEmpty();
            preg_match_all('/\{([^}]+)\}/', $path, $names);
            $parameters = collect($operation['parameters'] ?? [])->where('in', 'path')->keyBy('name');
            foreach ($names[1] as $name) {
                expect($parameters->get($name)['required'] ?? false)->toBeTrue('Missing required path parameter: '.$path.' '.$name);
            }
            $ids[$id] = strtoupper($method).' '.$normalize($path);
            $documented[$ids[$id]] = $id;
        }
    }
    $recorded = [];
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.$normalize('/'.$route->uri())] = $route;
        }
    }
    foreach ($ledger as $entry) {
        $key = $entry['method'].' '.$normalize($entry['path']);
        expect($documented[$key] ?? null)->toBe($entry['operation_id']);
        expect(isset($recorded[$key]))->toBeFalse('Duplicate ledger operation: '.$key);
        expect($entry['source'])->not->toBeEmpty();
        $route = $routes[$key];
        expect($entry['handler'])->toBe($route->getActionName());
        $middleware = $route->gatherMiddleware();
        $authentication = in_array('auth:provider', $middleware, true) ? 'provider'
            : (in_array('auth:sanctum', $middleware, true) ? (str_contains($entry['path'], '/admin/') ? 'admin' : 'patient') : 'public');
        expect($entry['authentication'])->toBe($authentication, 'Stale role contract: '.$key);
        $recorded[$key] = true;
    }
    expect(array_values(array_diff(array_keys($documented), array_keys($recorded))))->toBe([]);

    $collection = json_decode(file_get_contents(base_path('postman/Cozy-Health-API.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
    $saved = [];
    $visit = function (array $items) use (&$visit, &$saved, $ledger, $ids): void {
        foreach ($items as $item) {
            if (isset($item['item'])) {
                $visit($item['item']);

                continue;
            }
            if (! isset($item['request'])) {
                continue;
            }
            $id = $item['x-cozy-operation-id'];
            expect(isset($ids[$id]))->toBeTrue('Unknown saved operation: '.$id);
            $operation = collect($ledger)->firstWhere('operation_id', $id);
            $raw = is_string($item['request']['url']) ? $item['request']['url'] : $item['request']['url']['raw'];
            $path = explode('?', str_replace('{{base_url}}', '/api/v1', $raw))[0];
            $path = preg_replace('/\{\{[^}]+\}\}|:[A-Za-z_]\w*/', 'fixture', $path);
            $pattern = '#^'.preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($operation['path'], '#')).'$#';
            expect($item['request']['method'])->toBe($operation['method']);
            expect(preg_match($pattern, $path))->toBe(1, 'Stale saved URL: '.$raw);
            $saved[$id] = true;
        }
    };
    $visit($collection['item']);
    expect(array_values(array_diff(array_keys($ids), array_keys($saved))))->toBe([]);
});

it('documents authentication boundaries and retired operations without advertising working mutations', function () {
    $spec = json_decode(file_get_contents(base_path('openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
    $ledger = json_decode(file_get_contents(base_path('docs/API_OPERATION_COVERAGE.json')), true, flags: JSON_THROW_ON_ERROR);
    $normalize = fn (string $path) => preg_replace('/\{[^}]+\}/', '{}', $path);
    foreach ($ledger as $entry) {
        $path = collect(array_keys($spec['paths']))->first(fn ($p) => $normalize($p) === $normalize($entry['path']));
        $operation = $spec['paths'][$path][strtolower($entry['method'])];
        $scheme = match ($entry['authentication']) {
            'public' => [], 'provider' => [['providerAuth' => []]], 'admin' => [['adminAuth' => []]], default => [['bearerAuth' => []]],
        };
        expect($operation['security'])->toBe($scheme);
        if ($entry['classification'] === 'retired') {
            expect($operation['deprecated'])->toBeTrue();
            expect(array_keys($operation['responses']))->not->toContain(200, 201, 202, 204);
            expect($operation['responses'])->toHaveKey('410');
        }
    }
});

it('keeps corrected write examples valid and authentication examples bound to owned fixtures', function () {
    $collection = json_decode(file_get_contents(base_path('postman/Cozy-Health-API.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
    $examples = [];
    $visit = function (array $items) use (&$visit, &$examples): void {
        foreach ($items as $item) {
            if (isset($item['item'])) {
                $visit($item['item']);
            } elseif (isset($item['request']['body']['raw'])) {
                $request = $item['request'];
                $url = is_string($request['url']) ? $request['url'] : $request['url']['raw'];
                $examples[$request['method'].' '.$url] = json_decode($request['body']['raw'], true, flags: JSON_THROW_ON_ERROR);
            }
        }
    };
    $visit($collection['item']);
    foreach (['register', 'login'] as $action) {
        $example = $examples['POST {{base_url}}/auth/'.$action];
        expect($example['email'])->toBe('{{fixture_email}}');
        expect($example['password'])->toBe('{{fixture_password}}');
    }
    $mood = $examples['POST {{base_url}}/mood-entries'];
    $mood['id'] = (string) Str::uuid();
    expect(Validator::make($mood, (new MoodEntryRequest)->rules())->errors()->toArray())->toBe([]);
    $journal = $examples['POST {{base_url}}/journal-entries'];
    unset($journal['id']); // POST may assign its own ID; avoid a uniqueness query for this schema-only example check.
    $journal['client_created_at'] = now()->toIso8601String();
    $request = JournalEntryRequest::create('/api/v1/journal-entries', 'POST');
    expect(Validator::make($journal, $request->rules())->errors()->toArray())->toBe([]);
    $spec = json_decode(file_get_contents(base_path('openapi.yaml')), true, flags: JSON_THROW_ON_ERROR);
    expect($spec['paths']['/api/v1/journal-entries']['post']['requestBody']['content']['application/json']['schema']['required'])
        ->toContain('type', 'is_draft', 'client_created_at');
});
