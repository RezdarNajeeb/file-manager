<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncFilesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('minio');
    }

    public function test_sync_command_adds_new_files_from_bucket()
    {
        // Add files to the bucket
        Storage::disk('minio')->put('file1.txt', 'content 1');
        Storage::disk('minio')->put('file2.txt', 'content 2');

        $this->artisan('files:sync')
            ->expectsOutput('Starting file sync with Minio bucket...')
            ->expectsOutput('Found new file: file1.txt')
            ->expectsOutput('Found new file: file2.txt')
            ->expectsOutput('File sync completed successfully.')
            ->assertExitCode(0);

        // Check that files were added to database
        $this->assertDatabaseHas('file_uploads', ['path' => 'file1.txt']);
        $this->assertDatabaseHas('file_uploads', ['path' => 'file2.txt']);
    }

    public function test_sync_command_removes_files_not_in_bucket()
    {
        // Create database records for files
        $file1 = FileUpload::factory()->create(['path' => 'existing-file.txt']);
        $file2 = FileUpload::factory()->create(['path' => 'missing-file.txt']);

        // Only put one file in the bucket
        Storage::disk('minio')->put('existing-file.txt', 'content');

        $this->artisan('files:sync')
            ->expectsOutput('Starting file sync with Minio bucket...')
            ->expectsOutput('File not found in bucket, deleting from database: missing-file.txt')
            ->expectsOutput('File sync completed successfully.')
            ->assertExitCode(0);

        // Check that missing file was removed from database
        $this->assertDatabaseHas('file_uploads', ['id' => $file1->id]);
        $this->assertDatabaseMissing('file_uploads', ['id' => $file2->id]);
    }

    public function test_sync_command_handles_empty_bucket()
    {
        // Create database records but no files in bucket
        FileUpload::factory()->create(['path' => 'file1.txt']);
        FileUpload::factory()->create(['path' => 'file2.txt']);

        $this->artisan('files:sync')
            ->expectsOutput('Starting file sync with Minio bucket...')
            ->expectsOutput('File not found in bucket, deleting from database: file1.txt')
            ->expectsOutput('File not found in bucket, deleting from database: file2.txt')
            ->expectsOutput('File sync completed successfully.')
            ->assertExitCode(0);

        // Check that all files were removed from database
        $this->assertDatabaseCount('file_uploads', 0);
    }

    public function test_sync_command_preserves_existing_files()
    {
        // Create files both in bucket and database
        Storage::disk('minio')->put('existing1.txt', 'content 1');
        Storage::disk('minio')->put('existing2.txt', 'content 2');

        $file1 = FileUpload::factory()->create(['path' => 'existing1.txt']);
        $file2 = FileUpload::factory()->create(['path' => 'existing2.txt']);

        $this->artisan('files:sync')
            ->expectsOutput('Starting file sync with Minio bucket...')
            ->expectsOutput('File sync completed successfully.')
            ->assertExitCode(0);

        // Files should still exist in database
        $this->assertDatabaseHas('file_uploads', ['id' => $file1->id]);
        $this->assertDatabaseHas('file_uploads', ['id' => $file2->id]);
        $this->assertDatabaseCount('file_uploads', 2);
    }

    public function test_sync_command_sets_correct_file_attributes()
    {
        Storage::disk('minio')->put('test-file.txt', 'test content for sync');

        $this->artisan('files:sync')
            ->assertExitCode(0);

        $file = FileUpload::where('path', 'test-file.txt')->first();

        $this->assertNotNull($file);
        $this->assertEquals('test-file.txt', $file->filename);
        $this->assertEquals('test-file.txt', $file->original_filename);
        $this->assertEquals('completed', $file->status);
        $this->assertEquals(21, $file->file_size); // Length of 'test content for sync'
        $this->assertStringStartsWith('manual-', $file->tus_id);
        $this->assertEquals('text/plain', $file->mime_type);
    }

    public function test_sync_command_handles_mixed_scenario()
    {
        // Setup mixed scenario:
        // - file1: in both bucket and DB (should remain)
        // - file2: in DB only (should be removed)
        // - file3: in bucket only (should be added)

        Storage::disk('minio')->put('file1.txt', 'content 1');
        Storage::disk('minio')->put('file3.txt', 'content 3');

        $existingFile = FileUpload::factory()->create(['path' => 'file1.txt']);
        $orphanedFile = FileUpload::factory()->create(['path' => 'file2.txt']);

        $this->artisan('files:sync')
            ->expectsOutput('Starting file sync with Minio bucket...')
            ->expectsOutput('Found new file: file3.txt')
            ->expectsOutput('File not found in bucket, deleting from database: file2.txt')
            ->expectsOutput('File sync completed successfully.')
            ->assertExitCode(0);

        // Check final state
        $this->assertDatabaseHas('file_uploads', ['id' => $existingFile->id]);
        $this->assertDatabaseMissing('file_uploads', ['id' => $orphanedFile->id]);
        $this->assertDatabaseHas('file_uploads', ['path' => 'file3.txt']);
        $this->assertDatabaseCount('file_uploads', 2);
    }
}
