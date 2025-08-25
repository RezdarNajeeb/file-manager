<?php

namespace Tests\Unit;

use App\Services\TusService;
use App\Models\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use TusPhp\Tus\Server as TusServer;

class TusServiceTest extends TestCase
{
    use RefreshDatabase;

    private TusService $tusService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('minio');
        $this->tusService = new TusService();
    }

    public function test_tus_service_creates_server_instance()
    {
        $server = $this->tusService->getServer();

        $this->assertInstanceOf(TusServer::class, $server);
    }

    public function test_tus_service_sets_correct_configuration()
    {
        $server = $this->tusService->getServer();

        // Test that the server is properly configured
        $this->assertNotNull($server);
    }

    public function test_bucket_creation_check()
    {
        // Mock successful bucket check
        Storage::disk('minio')->put('test/file.txt', 'content');

        $result = $this->tusService->createBucket();

        $this->assertTrue($result);
    }

    public function test_move_to_minio_uploads_file_successfully()
    {
        // Create a temporary file for testing
        $tempFile = tempnam(sys_get_temp_dir(), 'test_upload');
        $testContent = 'This is test content for MinIO upload';
        file_put_contents($tempFile, $testContent);

        $filename = 'test-file.txt';

        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->tusService);
        $moveToMinioMethod = $reflection->getMethod('moveToMinio');
        $moveToMinioMethod->setAccessible(true);

        $result = $moveToMinioMethod->invoke($this->tusService, $tempFile, $filename);

        // Verify the file was uploaded with correct path structure
        $expectedPath = $filename;
        $this->assertEquals($expectedPath, $result);

        // Verify file exists in MinIO
        $this->assertTrue(Storage::disk('minio')->exists($result));

        // Verify file content
        $uploadedContent = Storage::disk('minio')->get($result);
        $this->assertEquals($testContent, $uploadedContent);

        // Clean up
        unlink($tempFile);
    }

    public function test_move_to_minio_throws_exception_when_local_file_not_found()
    {
        $nonExistentFile = '/path/to/non/existent/file.txt';
        $filename = 'test-file.txt';

        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->tusService);
        $moveToMinioMethod = $reflection->getMethod('moveToMinio');
        $moveToMinioMethod->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Local file not found');

        $moveToMinioMethod->invoke($this->tusService, $nonExistentFile, $filename);
    }
}
