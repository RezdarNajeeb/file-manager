<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('minio');
    }

    public function test_file_upload_page_loads_successfully()
    {
        $response = $this->get('/');

        $response->assertStatus(200)
            ->assertViewIs('files.index')
            ->assertViewHas('files');
    }

    public function test_files_api_returns_json()
    {
        FileUpload::factory()->count(3)->create([
            'status' => 'completed',
        ]);

        $response = $this->getJson('/files');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'filename',
                        'file_size',
                        'status',
                        'progress',
                        'created_at',
                        'download_url',
                    ],
                ],
            ]);
    }

    public function test_completed_file_can_be_downloaded()
    {
        Storage::disk('minio')->put('test-file.txt', 'test content');

        $file = FileUpload::factory()->create([
            'status' => 'completed',
            'path' => 'test-file.txt',
            'original_filename' => 'test.txt',
            'mime_type' => 'text/plain',
            'file_size' => 12,
        ]);

        $response = $this->get(route('files.download', $file));

        $response->assertStatus(200)
            ->assertHeader('Content-Disposition', 'attachment; filename="test.txt"')
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_incomplete_file_cannot_be_downloaded()
    {
        $file = FileUpload::factory()->create([
            'status' => 'uploading',
            'path' => 'test-file.txt',
        ]);

        $response = $this->get(route('files.download', $file));

        $response->assertStatus(404);
    }

    public function test_missing_file_returns_404()
    {
        $file = FileUpload::factory()->create([
            'status' => 'completed',
            'path' => 'non-existent-file.txt',
        ]);

        $response = $this->get(route('files.download', $file));

        $response->assertStatus(404);
    }

    public function test_file_can_be_deleted()
    {
        Storage::disk('minio')->put('test-file.txt', 'test content');

        $file = FileUpload::factory()->create([
            'status' => 'completed',
            'path' => 'test-file.txt',
        ]);

        $response = $this->deleteJson(route('files.destroy', $file));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('file_uploads', ['id' => $file->id]);
        Storage::disk('minio')->assertMissing('test-file.txt');
    }

    public function test_resumable_download_supports_range_requests()
    {
        $content = str_repeat('a', 1000);
        Storage::disk('minio')->put('test-file.txt', $content);

        $file = FileUpload::factory()->create([
            'status' => 'completed',
            'path' => 'test-file.txt',
            'file_size' => 1000,
            'mime_type' => 'text/plain',
        ]);

        $response = $this->get(route('files.download', $file), [
            'Range' => 'bytes=100-199',
        ]);

        $response->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 100-199/1000')
            ->assertHeader('Content-Length', '100')
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_upload_progress_can_be_retrieved()
    {
        $file = FileUpload::factory()->create([
            'tus_id' => 'test-tus-id-123',
            'status' => 'uploading',
            'file_size' => 1000,
            'bytes_uploaded' => 500,
        ]);

        $response = $this->getJson(route('upload.progress', 'test-tus-id-123'));

        $response->assertStatus(200)
            ->assertJson([
                'id' => $file->id,
                'tus_id' => 'test-tus-id-123',
                'progress' => 50.0,
            ]);
    }

    public function test_upload_progress_returns_404_for_invalid_tus_id()
    {
        $response = $this->getJson(route('upload.progress', 'invalid-tus-id'));

        $response->assertStatus(404)
            ->assertJson(['error' => 'Upload not found']);
    }

    public function test_tus_endpoint_handles_options_request()
    {
        $response = $this->options('/tus');

        $response->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, PUT, DELETE, OPTIONS, HEAD');
    }

    public function test_file_upload_model_calculates_progress_correctly()
    {
        $file = FileUpload::factory()->make([
            'file_size' => 1000,
            'bytes_uploaded' => 250,
        ]);

        $this->assertEquals(25.0, $file->upload_progress);
    }

    public function test_file_upload_model_formats_file_sizes()
    {
        $file = FileUpload::factory()->make(['file_size' => 1048576]); // 1MB
        $this->assertEquals('1.00 MB', $file->formatted_file_size);

        $file = FileUpload::factory()->make(['file_size' => 1073741824]); // 1GB
        $this->assertEquals('1.00 GB', $file->formatted_file_size);

        $file = FileUpload::factory()->make(['file_size' => 1024]); // 1KB
        $this->assertEquals('1.00 KB', $file->formatted_file_size);

        $file = FileUpload::factory()->make(['file_size' => 500]); // 500 bytes
        $this->assertEquals('500 B', $file->formatted_file_size);
    }

    public function test_file_upload_status_methods()
    {
        $completedFile = FileUpload::factory()->make(['status' => 'completed']);
        $this->assertTrue($completedFile->isCompleted());
        $this->assertFalse($completedFile->isUploading());
        $this->assertFalse($completedFile->isFailed());

        $uploadingFile = FileUpload::factory()->make(['status' => 'uploading']);
        $this->assertFalse($uploadingFile->isCompleted());
        $this->assertTrue($uploadingFile->isUploading());
        $this->assertFalse($uploadingFile->isFailed());

        $failedFile = FileUpload::factory()->make(['status' => 'failed']);
        $this->assertFalse($failedFile->isCompleted());
        $this->assertFalse($failedFile->isUploading());
        $this->assertTrue($failedFile->isFailed());
    }
}
