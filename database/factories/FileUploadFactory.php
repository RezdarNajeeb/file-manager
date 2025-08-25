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
            'filename' => $this->faker->word(),
            'original_filename' => $this->faker->word(),
            'mime_type' => $this->faker->word(),
            'file_size' => $this->faker->randomNumber(),
            'tus_id' => $this->faker->word(),
            'path' => $this->faker->word(),
            'status' => $this->faker->word(),
            'bytes_uploaded' => $this->faker->randomNumber(),
            'metadata' => $this->faker->words(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }
}
