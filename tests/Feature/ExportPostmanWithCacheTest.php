<?php

namespace Samody\PostmanGenerator\Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Concerns\HandlesRoutes;
use Samody\PostmanGenerator\Tests\Fixtures\CollectionHelpersTrait;
use Samody\PostmanGenerator\Tests\TestCase;

class ExportPostmanWithCacheTest extends TestCase
{
    use CollectionHelpersTrait, HandlesRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defineCacheRoutes(<<<'PHP'
<?php
Route::middleware('api')->group(function () {
    Route::get('serialized-route', function () {
        return 'Serialized Route';
    });
});
PHP);

        config()->set('api-postman.filename', 'test.json');
        config()->set('api-postman.smart_naming', false);
        config()->set('api-postman.structured', false);
        config()->set('api-postman.group_by', 'none');

        Storage::disk()->deleteDirectory('postman');
    }

    public function test_cached_export_works()
    {
        $this->get('serialized-route')
            ->assertOk()
            ->assertSee('Serialized Route');

        $this->artisan('export:postman')->assertExitCode(0);

        $collection = json_decode(Storage::get('postman/'.config('api-postman.filename')), true);

        $routes = $this->app['router']->getRoutes();

        $processedCount = 0;
        foreach ($routes as $route) {
            $middlewares = $route->gatherMiddleware();
            $included = false;
            foreach ($middlewares as $middleware) {
                if (in_array($middleware, config('api-postman.include_middleware'))) {
                    $included = true;
                    break;
                }
            }
            if ($included) {
                $methods = array_filter($route->methods(), fn ($value) => $value !== 'HEAD');
                $processedCount += count($methods);
            }
        }

        $collectionItems = $collection['item'];

        $totalCollectionItems = $this->countCollectionItems($collection['item']);

        $this->assertEquals($processedCount, $totalCollectionItems);

        foreach ($routes as $route) {
            $middlewares = $route->gatherMiddleware();
            $included = false;
            foreach ($middlewares as $middleware) {
                if (in_array($middleware, config('api-postman.include_middleware'))) {
                    $included = true;
                    break;
                }
            }
            if (! $included) {
                continue;
            }

            $methods = array_filter($route->methods(), fn ($value) => $value !== 'HEAD');

            foreach ($methods as $method) {
                $collectionRoute = Arr::first($collectionItems, function ($item) use ($route, $method) {
                    return $item['name'] == $route->uri() && $item['request']['method'] == $method;
                });
                $this->assertNotNull($collectionRoute);
            }
        }
    }
}
