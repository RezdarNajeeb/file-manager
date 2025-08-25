<?php

namespace Tests\Unit;

use App\Models\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileUploadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_upload_model_has_correct_fillable_attributes()
    {
        $fileUpload = new FileUpload();

        $expectedFillable = [
            'filename',
            'original_filename',
            'mime_type',
            'file_size',
            'tus_id',
            'path',
            'status',
            'bytes_uploaded',
            'metadata',
        ];

        $this->assertEquals($expectedFillable, $fileUpload->getFillable());
    }

    public function test_file_upload_model_casts_attributes_correctly()
    {
        $fileUpload = new FileUpload();

        $expectedCasts = [
            'metadata' => 'array',
            'file_size' => 'integer',
            'bytes_uploaded' => 'integer',
        ];

        foreach ($expectedCasts as $attribute => $expectedCast) {
            $this->assertEquals($expectedCast, $fileUpload->getCasts()[$attribute]);
        }
    }

    public function test_upload_progress_calculation_with_zero_file_size()
    {
        $fileUpload = FileUpload::factory()->make([
            'file_size' => 0,
            'bytes_uploaded' => 0
        ]);

        $this->assertEquals(0, $fileUpload->upload_progress);
    }

    public function test_upload_progress_calculation_with_partial_upload()
    {
        $fileUpload = FileUpload::factory()->make([
            'file_size' => 1000,
            'bytes_uploaded' => 750
        ]);

        $this->assertEquals(75.0, $fileUpload->upload_progress);
    }

    public function test_upload_progress_calculation_with_complete_upload()
    {
        $fileUpload = FileUpload::factory()->make([
            'file_size' => 1000,
            'bytes_uploaded' => 1000
        ]);

        $this->assertEquals(100.0, $fileUpload->upload_progress);
    }

    public function test_formatted_bytes_uploaded_calculation()
    {
        $fileUpload = FileUpload::factory()->make(['bytes_uploaded' => 2048]);

        $this->assertEquals('2.00 KB', $fileUpload->formatted_bytes_uploaded);
    }

    public function test_file_size_formatting_edge_cases()
    {
        // Test bytes
        $fileUpload = FileUpload::factory()->make(['file_size' => 999]);
        $this->assertEquals('999 B', $fileUpload->formatted_file_size);

        // Test KB boundary
        $fileUpload = FileUpload::factory()->make(['file_size' => 1024]);
        $this->assertEquals('1.00 KB', $fileUpload->formatted_file_size);

        // Test MB boundary
        $fileUpload = FileUpload::factory()->make(['file_size' => 1048576]);
        $this->assertEquals('1.00 MB', $fileUpload->formatted_file_size);

        // Test GB boundary
        $fileUpload = FileUpload::factory()->make(['file_size' => 1073741824]);
        $this->assertEquals('1.00 GB', $fileUpload->formatted_file_size);

        // Test large GB
        $fileUpload = FileUpload::factory()->make(['file_size' => 5368709120]); // 5GB
        $this->assertEquals('5.00 GB', $fileUpload->formatted_file_size);
    }

    public function test_status_check_methods_are_case_sensitive()
    {
        $fileUpload = FileUpload::factory()->make(['status' => 'COMPLETED']);
        $this->assertFalse($fileUpload->isCompleted());

        $fileUpload = FileUpload::factory()->make(['status' => 'completed']);
        $this->assertTrue($fileUpload->isCompleted());
    }

    public function test_model_factory_creates_valid_instances()
    {
        $fileUpload = FileUpload::factory()->make();

        $this->assertNotNull($fileUpload->filename);
        $this->assertNotNull($fileUpload->original_filename);
        $this->assertNotNull($fileUpload->mime_type);
        $this->assertIsInt($fileUpload->file_size);
        $this->assertNotNull($fileUpload->tus_id);
        $this->assertNotNull($fileUpload->path);
        $this->assertNotNull($fileUpload->status);
        $this->assertIsInt($fileUpload->bytes_uploaded);
    }

    public function test_metadata_casting_works_correctly()
    {
        $metadata = ['filename' => 'test.txt', 'type' => 'document'];

        $fileUpload = FileUpload::factory()->create([
            'metadata' => $metadata
        ]);

        $this->assertIsArray($fileUpload->metadata);
        $this->assertEquals($metadata, $fileUpload->metadata);

        // Test retrieval from database
        $retrieved = FileUpload::find($fileUpload->id);
        $this->assertIsArray($retrieved->metadata);
        $this->assertEquals($metadata, $retrieved->metadata);
    }
}
