<?php

namespace Samody\PostmanGenerator\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Samody\PostmanGenerator\Tests\Fixtures\CollectionHelpersTrait;
use Samody\PostmanGenerator\Tests\TestCase;

class SmartNamingAndGroupingTest extends TestCase
{
    use CollectionHelpersTrait;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('api-postman.filename', 'test.json');

        Storage::disk()->deleteDirectory('postman');
    }

    public function test_smart_naming_works()
    {
        config([
            'api-postman.smart_naming' => true,
            'api-postman.group_by' => 'controller',
        ]);

        $this->artisan('export:postman')->assertExitCode(0);

        $collection = json_decode(Storage::get('postman/'.config('api-postman.filename')), true);

        // Check a known route: example/index (GET)
        $items = collect($collection['item']);

        // Since grouping is set to 'controller', we find it in the 'Example' folder
        $exampleFolder = $items->where('name', 'Example')->first();
        $this->assertNotNull($exampleFolder);

        $indexRequest = collect($exampleFolder['item'])->where('name', 'Get Example Index')->first();
        $this->assertNotNull($indexRequest, 'Smart name "Get Example Index" should be generated for GET example/index');
    }

    public function test_controller_grouping_works()
    {
        config([
            'api-postman.group_by' => 'controller',
            'api-postman.smart_naming' => false,
        ]);

        $this->artisan('export:postman')->assertExitCode(0);

        $collection = json_decode(Storage::get('postman/'.config('api-postman.filename')), true);

        $items = collect($collection['item']);

        // Should have an 'Example' folder and an 'AuditLog' folder
        $this->assertTrue($items->contains('name', 'Example'));
        $this->assertTrue($items->contains('name', 'AuditLog'));

        $exampleFolder = $items->where('name', 'Example')->first();
        $this->assertCount(8, $exampleFolder['item']); // Based on TestCase routes
    }

    public function test_path_grouping_works()
    {
        config([
            'api-postman.group_by' => 'path',
            'api-postman.smart_naming' => false,
        ]);

        $this->artisan('export:postman')->assertExitCode(0);

        $collection = json_decode(Storage::get('postman/'.config('api-postman.filename')), true);

        $items = collect($collection['item']);

        // Should have an 'example' folder (first segment of URI)
        $this->assertTrue($items->contains('name', 'example'));

        $exampleFolder = $items->where('name', 'example')->first();
        // Inside 'example' there should be subfolders or items
        $this->assertNotEmpty($exampleFolder['item']);
    }

    public function test_nested_path_grouping_works()
    {
        config([
            'api-postman.group_by' => 'path',
            'api-postman.smart_naming' => true,
            'api-postman.crud_folders' => false,
        ]);

        $this->artisan('export:postman')->assertExitCode(0);

        $collection = json_decode(Storage::get('postman/'.config('api-postman.filename')), true);

        $items = collect($collection['item']);

        // example.users.audit-logs.index
        $exampleFolder = $items->where('name', 'example')->first();
        $usersFolder = collect($exampleFolder['item'])->where('name', 'users')->first();
        $this->assertNotNull($usersFolder);

        $auditLogsFolder = collect($usersFolder['item'])->where('name', 'audit-logs')->first();
        $this->assertNotNull($auditLogsFolder);

        $indexRequest = collect($auditLogsFolder['item'])->where('name', 'Get Users Example Audit Logs')->first();
        $this->assertNotNull($indexRequest);
    }
}
