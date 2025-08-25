<?php

namespace Database\Factories;

use App\Models\FileUpload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class FileUploadFactory extends Factory
{
    protected $model = FileUpload::class;

    public function definition(): array
    {
        return [
            'filename' => $this->faker->words(1, true) . '.' . $this->faker->fileExtension(),
            'original_filename' => $this->faker->words(1, true) . '.' . $this->faker->fileExtension(),
            'mime_type' => $this->faker->mimeType(),
            'file_size' => $this->faker->numberBetween(1000, 10000000),
            'tus_id' => $this->faker->uuid(),
            'path' => $this->faker->filePath(),
            'status' => $this->faker->randomElement(['pending', 'uploading', 'completed', 'failed']),
            'bytes_uploaded' => $this->faker->numberBetween(0, 5000000),
            'metadata' => ['filename' => $this->faker->words(1, true), 'type' => $this->faker->words(1, true)],
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }
}
