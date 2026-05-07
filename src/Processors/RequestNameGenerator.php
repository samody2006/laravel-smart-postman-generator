<?php

declare(strict_types=1);

namespace Samody\PostmanGenerator\Processors;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final readonly class RequestNameGenerator
{
    private const VERB_MAP = [
        'GET'    => 'Get',
        'POST'   => 'Create',
        'PUT'    => 'Update',
        'PATCH'  => 'Update',
        'DELETE' => 'Delete',
    ];

    public function generate(Route $route, string $method): string
    {
        $uri = $route->uri();

        // 1. Remove common prefixes
        $uri = preg_replace('/^(api\/)?(v\d+\/)?/', '', $uri);

        // 2. Remove route parameters like {id}
        $uri = preg_replace('/\{[[:alnum:]_]+\}/', '', $uri);

        // 3. Clean up multiple slashes and trailing slashes
        $uri = trim(str_replace('//', '/', $uri), '/');

        // 4. Map the HTTP method to a verb
        $verb = self::VERB_MAP[strtoupper($method)] ?? 'Request';

        // 5. Transform segments into a readable name
        $segments = collect(explode('/', $uri))
            ->filter()
            ->map(fn ($segment) => Str::of($segment)->replace(['-', '_'], ' ')->title()->toString())
            ->reverse();

        if ($segments->isEmpty()) {
            return $verb . ' Request';
        }

        $resource = $segments->shift();
        $context = $segments->implode(' ');

        return trim("{$verb} {$context} {$resource}");
    }
}
