<?php

namespace Samody\PostmanGenerator\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Samody\PostmanGenerator\Tests\Fixtures\CollectionHelpersTrait;
use Samody\PostmanGenerator\Tests\TestCase;

class ExportPostmanPathFilterTest extends TestCase
{
    use CollectionHelpersTrait;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('api-postman.filename', 'test.json');

        Storage::disk()->deleteDirectory('postman');
    }

    public function test_export_can_be_filtered_by_path()
    {
        $this->artisan('export:postman --path=example/index')->assertExitCode(0);

        $collection = json_decode(Storage::get('postman/'.config('api-postman.filename')), true);

        $totalCollectionItems = $this->countCollectionItems($collection['item']);

        $this->assertEquals(1, $totalCollectionItems);
        $this->assertEquals('example/index', $collection['item'][0]['name']);
    }

    public function test_export_can_be_filtered_by_partial_path()
    {
        $this->artisan('export:postman --path=example/users')->assertExitCode(0);

        $collection = json_decode(Storage::get('postman/'.config('api-postman.filename')), true);

        $totalCollectionItems = $this->countCollectionItems($collection['item']);

        // users.audit-logs (index, store, show, update, destroy) = 5
        // users.other_logs (5)
        // users.someLogs (5)
        // total = 15
        $this->assertEquals(15, $totalCollectionItems);
    }
}
