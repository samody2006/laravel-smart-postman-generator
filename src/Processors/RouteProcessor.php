<?php

namespace Samody\PostmanGenerator\Processors;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Illuminate\Validation\ValidationRuleParser;
use ReflectionClass;
use ReflectionFunction;
use Samody\PostmanGenerator\Concerns\HasAuthentication;

class RouteProcessor
{
    use HasAuthentication;

    private array $config;

    private Router $router;

    private RequestNameGenerator $nameGenerator;

    private array $output;

    public function __construct(Repository $config, Router $router, RequestNameGenerator $nameGenerator)
    {
        $this->config = $config['api-postman'];

        $this->router = $router;

        $this->nameGenerator = $nameGenerator;

        $this->resolveAuth();
    }

    public function process(array $output): array
    {
        $this->output = $output;

        $routes = collect($this->router->getRoutes());

        /** @var Route $route */
        foreach ($routes as $route) {
            if ($this->config['path'] && ! Str::is($this->config['path'].'*', $route->uri())) {
                continue;
            }

            $this->processRoute($route);
        }

        return $this->output;
    }

    /**
     * @throws \ReflectionException
     */
    protected function processRoute(Route $route)
    {
        try {
            $methods = array_filter($route->methods(), fn ($value) => $value !== 'HEAD');
            $middlewares = $route->gatherMiddleware();

            foreach ($methods as $method) {
                $includedMiddleware = false;

                foreach ($middlewares as $middleware) {
                    if (in_array($middleware, $this->config['include_middleware'])) {
                        $includedMiddleware = true;
                    }
                }

                if (empty($middlewares) || ! $includedMiddleware) {
                    continue;
                }

                $reflectionMethod = $this->getReflectionMethod($route->getAction());

                if (! $reflectionMethod) {
                    continue;
                }

                $routeHeaders = $this->config['headers'];

                if ($this->authentication && in_array($this->config['auth_middleware'], $middlewares)) {
                    $routeHeaders[] = $this->authentication->toArray();
                }

                $uri = Str::of($route->uri())->replaceMatches('/{([[:alnum:]_]+)}/', ':$1');

                if ($this->config['include_doc_comments']) {
                    $description = (new DocBlockProcessor)($reflectionMethod);
                }

                $data = [
                    'name' => $this->config['smart_naming']
                        ? $this->nameGenerator->generate($route, $method)
                        : $route->uri(),
                    'request' => $this->processRequest(
                        $method,
                        $uri,
                        $this->config['enable_formdata'] ? (new FormDataProcessor)->process($reflectionMethod) : collect()
                    ),
                    'response' => $this->processResponse($method, $route, $reflectionMethod),

                    'protocolProfileBehavior' => [
                        'disableBodyPruning' => $this->config['protocol_profile_behavior']['disable_body_pruning'] ?? false,
                    ],
                ];

                $data['request']['description'] = $description ?? '';

                $groupBy = $this->config['group_by'] ?? ($this->config['structured'] ? 'path' : 'none');

                if ($groupBy !== 'none') {
                    if ($groupBy === 'controller') {
                        $routeNameSegments = collect([
                            Str::of($route->getActionName())
                                ->before('@')
                                ->afterLast('\\')
                                ->replace('Controller', '')
                                ->toString(),
                        ]);
                    } else {
                        $routeNameSegments = (
                            $route->getName()
                                ? Str::of($route->getName())->explode('.')
                                : Str::of($route->uri())->after('api/')->explode('/')
                        )->filter(fn ($value) => ! is_null($value) && $value !== '');

                        if (! $this->config['crud_folders']) {
                            if (in_array($routeNameSegments->last(), ['index', 'store', 'show', 'update', 'destroy'])) {
                                $routeNameSegments->forget($routeNameSegments->count() - 1);
                            }
                        }
                    }

                    $this->buildTree($this->output, $routeNameSegments->all(), $data);
                } else {
                    $this->output['item'][] = $data;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to process route: '.$route->uri());
        }
    }

    protected function processRequest(string $method, Stringable $uri, Collection $rules): array
    {
        $headers = collect($this->config['headers'])
            ->push($this->authentication?->toArray())
            ->filter()
            ->values()
            ->all();

        return collect([
            'method' => strtoupper($method),
            'header' => $headers,
            'url' => [
                'raw' => '{{base_url}}/'.$uri,
                'host' => ['{{base_url}}'],
                'path' => $uri->explode('/')->filter()->all(),
                'variable' => $uri
                    ->matchAll('/(?<={)[[:alnum:]]+(?=})/m')
                    ->transform(function ($variable) {
                        return ['key' => $variable, 'value' => ''];
                    })
                    ->all(),
            ],
        ])
            ->when($rules, function (Collection $collection, Collection $rules) use ($method) {
                if ($rules->isEmpty()) {
                    return $collection;
                }

                if ($method === 'GET') {
                    return $collection->mergeRecursive([
                        'url' => [
                            'query' => $rules->transform(fn ($rule, $name) => [
                                'key' => $name,
                                'value' => $this->config['formdata'][$name] ?? null,
                                'description' => $this->config['print_rules'] ? $this->parseRulesIntoHumanReadable($name, $rule) : null,
                                'disabled' => false,
                            ])->values()->all(),
                        ],
                    ]);
                }

                $bodyMode = $this->config['body_mode'] ?? 'default';

                if ($bodyMode === 'auto') {
                    $bodyMode = (new BodyModeResolver)->resolve($rules->all());
                }

                if ($bodyMode === 'default') {
                    if ($this->config['body_format'] === 'json') {
                        return $collection->put('body', [
                            'mode' => 'raw',
                            'raw' => json_encode($rules->mapWithKeys(fn ($rule, $name) => [$name => $this->config['formdata'][$name] ?? null])->all(), JSON_PRETTY_PRINT),
                            'options' => [
                                'raw' => [
                                    'language' => 'json',
                                ],
                            ],
                        ]);
                    }

                    return $collection->put('body', [
                        'mode' => 'urlencoded',
                        'urlencoded' => $rules->map(fn ($rule, $name) => [
                            'key' => $name,
                            'value' => $this->config['formdata'][$name] ?? null,
                            'description' => $this->config['print_rules'] ? $this->parseRulesIntoHumanReadable($name, $rule) : null,
                        ])->values()->all(),
                    ]);
                }

                if ($bodyMode === 'json') {
                    return $collection->put('body', [
                        'mode' => 'raw',
                        'raw' => json_encode((new RequestSchemaBuilder)->build($rules->all()), JSON_PRETTY_PRINT),
                        'options' => [
                            'raw' => [
                                'language' => 'json',
                            ],
                        ],
                    ]);
                }

                if ($bodyMode === 'formdata') {
                    return $collection->put('body', [
                        'mode' => 'formdata',
                        'formdata' => $rules->map(fn ($rule, $name) => [
                            'key' => $name,
                            'value' => $this->config['formdata'][$name] ?? null,
                            'description' => $this->config['print_rules'] ? $this->parseRulesIntoHumanReadable($name, $rule) : null,
                            'type' => 'text',
                        ])->values()->all(),
                    ]);
                }

                return $collection;
            })
            ->all();
    }

    protected function processResponse(string $method, Route $route, object $reflectionMethod): array
    {
        $examples = $this->config['response_examples'] ?? [];
        $action = $route->getActionName();
        $example = $examples[$route->uri()] ?? $examples[$action] ?? null;
        $status = 200;

        if ($example === null && $reflectionMethod->getDocComment()) {
            if (preg_match('/@response\s+(\d{3})\s+(\{[^\r\n]*\}|\[[^\r\n]*\])/', $reflectionMethod->getDocComment(), $matches)) {
                $status = (int) $matches[1];
                $example = json_decode($matches[2], true);
            }
        }

        if (is_string($example)) {
            $decoded = json_decode($example, true);
            $example = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        $source = $this->getMethodSource($reflectionMethod);
        $inferred = $example === null ? $this->inferResponseFromSource($source, $reflectionMethod) : [];

        if ($example === null && ! empty($inferred)) {
            $example = $inferred[0]['body'];
            $status = $inferred[0]['code'];
        }

        if ($example === null) {
            $type = $reflectionMethod->getReturnType();
            $typeName = $type && method_exists($type, 'getName') ? $type->getName() : null;
            switch ($typeName) {
                case 'array':
                    $example = new \stdClass;
                    break;
                case 'bool':
                    $example = false;
                    break;
                case 'int':
                    $example = 0;
                    break;
                case 'float':
                    $example = 0.0;
                    break;
                case 'string':
                    $example = '';
                    break;
                default:
                    $example = new \stdClass;
            }
        }

        $responses = [[
            'name' => $status.' Example',
            'originalRequest' => [
                'method' => strtoupper($method),
                'header' => [],
                'url' => '{{base_url}}/'.$route->uri(),
            ],
            'status' => $this->responseStatusText($status),
            'code' => $status,
            '_postman_previewlanguage' => 'json',
            'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
            'cookie' => [],
            'body' => json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]];

        foreach (array_slice($inferred, 1) as $inferredResponse) {
            $responses[] = [
                'name' => $inferredResponse['code'].' Example',
                'originalRequest' => [
                    'method' => strtoupper($method),
                    'header' => [],
                    'url' => '{{base_url}}/'.$route->uri(),
                ],
                'status' => $inferredResponse['code'] === 403 ? 'Forbidden' : 'Error',
                'code' => $inferredResponse['code'],
                '_postman_previewlanguage' => 'json',
                'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                'cookie' => [],
                'body' => json_encode($inferredResponse['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $responses;
    }

    private function getMethodSource(object $method): string
    {
        if (! method_exists($method, 'getFileName') || ! $method->getFileName()) {
            return '';
        }

        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    private function responseStatusText(int $status): string
    {
        $statuses = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            422 => 'Unprocessable Entity',
            500 => 'Internal Server Error',
        ];

        return $statuses[$status] ?? 'Response';
    }

    /** Infer common Laravel response helper patterns without executing application code. */
    private function inferResponseFromSource(string $source, object $reflectionMethod): array
    {
        $responses = [];

        $resource = $this->inferResourceFromSource($source, $reflectionMethod);
        if ($resource !== null) {
            $responses[] = ['code' => 200, 'body' => $resource];
        }

        if (preg_match('/(?:\$this->)?jsonResponse\s*\((.*)\)\s*;/s', $source, $match)) {
            $arguments = $this->splitArguments($match[1]);
            $data = isset($arguments[0]) ? $this->parseLiteral($arguments[0]) : null;
            $message = isset($arguments[1]) ? $this->parseLiteral($arguments[1]) : null;
            $body = [];
            if ($message !== null) {
                $body['message'] = $message;
            }
            $body['data'] = $data;
            array_unshift($responses, ['code' => 200, 'body' => $body]);
        }

        if (preg_match('/abort(?:_unless|_if)?\s*\([^;]*?,\s*(\d{3})\s*,\s*([\'"])(.*?)\2\s*\)/s', $source, $match)) {
            $error = ['message' => stripcslashes($match[3])];
            $responses[] = ['code' => (int) $match[1], 'body' => $error];
        }

        return $responses;
    }

    private function inferResourceFromSource(string $source, object $reflectionMethod): ?array
    {
        if (! preg_match('/return\s+(?:new\s+)?([\\\\A-Za-z_][\\\\A-Za-z0-9_]*Resource)(?:::collection)?\s*\(/', $source, $match)) {
            return null;
        }

        $resourceName = $match[1];
        if ($resourceName[0] !== '\\' && strpos($resourceName, '\\') === false) {
            $classFile = $reflectionMethod->getDeclaringClass()->getFileName();
            $classSource = $classFile ? file_get_contents($classFile) : $source;
            $imported = false;
            if (preg_match_all('/^\s*use\s+([^;]+);/mi', $classSource, $imports)) {
                foreach ($imports[1] as $import) {
                    $parts = preg_split('/\s+as\s+/i', trim($import));
                    $importClass = trim($parts[0], " \\\\\t\\r\\n");
                    $alias = isset($parts[1]) ? trim($parts[1]) : substr(strrchr('\\'.$importClass, '\\'), 1);
                    if ($alias === $resourceName) {
                        $resourceName = $importClass;
                        $imported = true;
                        break;
                    }
                }
            }
            if (! $imported && preg_match('/namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/', $classSource, $namespace)) {
                $resourceName = trim($namespace[1], '\\').'\\'.$resourceName;
            }
        }

        $resourceName = ltrim($resourceName, '\\');
        if (! class_exists($resourceName)) {
            return null;
        }

        try {
            $resource = new ReflectionClass($resourceName);
            if (! $resource->hasMethod('toArray')) {
                return null;
            }
            $toArraySource = $this->getMethodSource($resource->getMethod('toArray'));
        } catch (\ReflectionException $exception) {
            return null;
        }

        if (! preg_match('/return\s*\[([\s\S]*?)\];/', $toArraySource, $arrayMatch)) {
            return null;
        }

        $fields = [];
        if (preg_match_all('/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*=>\s*([^,\r\n]+)/', $arrayMatch[1], $fieldMatches, PREG_SET_ORDER)) {
            foreach ($fieldMatches as $fieldMatch) {
                $fields[$fieldMatch[1]] = $this->sampleForField($fieldMatch[1], trim($fieldMatch[2]));
            }
        }

        if (empty($fields)) {
            return null;
        }

        return strpos($match[0], '::collection') !== false
            ? ['data' => [$fields]]
            : ['data' => $fields];
    }

    private function sampleForField(string $field, string $expression)
    {
        $literal = $this->parseLiteral($expression);
        if ($literal !== null || trim($expression) === 'null') {
            return $literal;
        }

        $name = strtolower($field);
        if (preg_match('/(^|_)(id|count|total|amount|price|quantity|number|age)(_|$)/', $name)) {
            return 1;
        }
        if (preg_match('/(^|_)(is|has|can|enabled|active|verified|published)(_|$)/', $name)) {
            return true;
        }
        if (strpos($name, 'email') !== false) {
            return 'jane@example.com';
        }
        if (strpos($name, 'date') !== false || strpos($name, '_at') !== false) {
            return '2026-01-01T00:00:00Z';
        }

        return '';
    }

    private function splitArguments(string $arguments): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $length = strlen($arguments);

        for ($index = 0; $index < $length; $index++) {
            $character = $arguments[$index];
            if ($quote !== null) {
                if ($character === $quote && ($index === 0 || $arguments[$index - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
            } elseif ($character === '(' || $character === '[' || $character === '{') {
                $depth++;
            } elseif ($character === ')' || $character === ']' || $character === '}') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $parts[] = trim(substr($arguments, $start, $index - $start));
                $start = $index + 1;
            }
        }

        $parts[] = trim(substr($arguments, $start));

        return $parts;
    }

    private function parseLiteral(string $expression)
    {
        $expression = trim($expression);
        if ($expression === 'null') {
            return null;
        }
        if ($expression === 'true' || $expression === 'false') {
            return $expression === 'true';
        }
        if (preg_match('/^([\'"])(.*)\1$/s', $expression, $match)) {
            return stripcslashes($match[2]);
        }
        if (is_numeric($expression)) {
            return strpos($expression, '.') === false ? (int) $expression : (float) $expression;
        }

        return null;
    }

    /**
     * @throws \ReflectionException
     */
    private function getReflectionMethod(array $routeAction): ?object
    {
        if ($this->containsSerializedClosure($routeAction)) {
            $routeAction['uses'] = unserialize($routeAction['uses'])->getClosure();
        }

        if ($routeAction['uses'] instanceof Closure) {
            return new ReflectionFunction($routeAction['uses']);
        }

        $routeData = explode('@', $routeAction['uses']);
        $reflection = new ReflectionClass($routeData[0]);

        if (! $reflection->hasMethod($routeData[1])) {
            return null;
        }

        return $reflection->getMethod($routeData[1]);
    }

    private function containsSerializedClosure(array $action): bool
    {
        return is_string($action['uses']) && Str::startsWith($action['uses'], [
            'C:32:"Opis\\Closure\\SerializableClosure',
            'O:47:"Laravel\SerializableClosure\\SerializableClosure',
            'O:55:"Laravel\\SerializableClosure\\UnsignedSerializableClosure',
        ]);
    }

    protected function buildTree(array &$routes, array $segments, array $request): void
    {
        $parent = &$routes;
        $destination = end($segments);

        foreach ($segments as $segment) {
            $matched = false;

            foreach ($parent['item'] as &$item) {
                if ($item['name'] === $segment) {
                    $parent = &$item;

                    if ($segment === $destination) {
                        $parent['item'][] = $request;
                    }

                    $matched = true;

                    break;
                }
            }

            unset($item);

            if (! $matched) {
                $item = [
                    'name' => $segment,
                    'item' => $segment === $destination ? [$request] : [],
                ];

                $parent['item'][] = &$item;
                $parent = &$item;
            }

            unset($item);
        }
    }

    protected function parseRulesIntoHumanReadable($attribute, $rules): string
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        // ... bail if user has asked for non interpreted strings:
        if (! $this->config['rules_to_human_readable']) {
            if (is_array($rules)) {
                foreach ($rules as $i => $rule) {
                    // because we don't support custom rule classes, we remove them from the rules
                    if (is_object($rule) && ! method_exists($rule, '__toString')) {
                        unset($rules[$i]);
                    }
                }
            }

            return is_array($rules)
                ? implode(', ', array_map(fn ($rule) => (string) $rule, $rules))
                : (is_object($rules) ? $this->safelyStringifyClassBasedRule($rules) : (string) $rules);
        }

        /*
         * An object based rule is presumably a Laravel default class based rule or one that implements the Illuminate
         * Rule interface. Lets try to safely access the string representation...
         */
        if (is_object($rules)) {
            $rules = [$this->safelyStringifyClassBasedRule($rules)];
        }

        /*
         * Handle string based rules (e.g. required|string|max:30)
         */
        if (is_array($rules)) {
            foreach ($rules as $i => $rule) {
                if (is_object($rule)) {
                    unset($rules[$i]);
                }
            }

            $validator = Validator::make([], [$attribute => implode('|', $rules)]);

            foreach ($rules as $rule) {
                [$rule, $parameters] = ValidationRuleParser::parse($rule);

                $validator->addFailure($attribute, $rule, $parameters);
            }

            $messages = $validator->getMessageBag()->toArray()[$attribute];

            if (is_array($messages)) {
                $messages = $this->handleEdgeCases($messages);
            }

            return implode(', ', is_array($messages) ? $messages : $messages->toArray());
        }

        // ...safely return a safe value if we encounter neither a string or object based rule set:
        return '';
    }

    protected function handleEdgeCases(array $messages): array
    {
        foreach ($messages as $key => $message) {
            if ($message === 'validation.nullable') {
                $messages[$key] = '(Nullable)';

                continue;
            }

            if ($message === 'validation.sometimes') {
                $messages[$key] = '(Optional)';
            }
        }

        return $messages;
    }

    /**
     * In this case we have received what is most likely a Rule Object but are not certain.
     */
    protected function safelyStringifyClassBasedRule($probableRule): string
    {
        if (! is_object($probableRule) || is_subclass_of($probableRule, Rule::class) || ! method_exists($probableRule, '__toString')) {
            return '';
        }

        return (string) $probableRule;
    }
}
