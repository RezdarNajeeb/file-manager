<?php

namespace App\Console\Commands;

use App\Models\FileUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncFilesWithBucket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync files from Minio bucket with the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting file sync with Minio bucket...');

        $disk = Storage::disk('minio');
        $bucketFiles = $disk->allFiles();

        $dbFiles = FileUpload::pluck('path')->toArray();

        // Add new files from bucket to DB
        foreach ($bucketFiles as $bucketFile) {
            if (!in_array($bucketFile, $dbFiles)) {
                $this->info("Found new file: {$bucketFile}");
                FileUpload::create([
                    'filename' => $bucketFile,
                    'original_filename' => basename($bucketFile),
                    'path' => $bucketFile,
                    'file_size' => $disk->size($bucketFile),
                    'mime_type' => $disk->mimeType($bucketFile),
                    'status' => 'completed',
                    'tus_id' => 'manual-' . uniqid(),
                ]);
                $this->info("Added file {$bucketFile} to database.");
            }
        }

        // Remove files from DB that are not in the bucket
        $dbFileRecords = FileUpload::all();
        foreach ($dbFileRecords as $dbFileRecord) {
            if (!in_array($dbFileRecord->path, $bucketFiles)) {
                $this->info("File not found in bucket, deleting from database: {$dbFileRecord->path}");
                $dbFileRecord->delete();
            }
        }

        $this->info('File sync completed successfully.');
    }
}
