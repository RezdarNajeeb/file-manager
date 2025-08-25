<?php

namespace App\Services;

use App\Models\FileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TusPhp\Cache\FileStore;
use TusPhp\Events\TusEvent;
use TusPhp\Tus\Server as TusServer;

class TusService
{
    private TusServer $server;

    public function __construct()
    {
        $this->server = new TusServer;
        $this->setupTusServer();
    }

    private function setupTusServer(): void
    {
        // Set cache adapter
        $this->server->setCache(
            new FileStore(storage_path('app/tus-cache'))
        );

        $this->server->setApiPath('/tus');

        // Set upload directory
        $this->server->setUploadDir(storage_path('app/tus-uploads'));

        // Set maximum upload size (5GB)
        $this->server->setMaxUploadSize(5 * 1024 * 1024 * 1024);

        // Setup event listeners
        $this->setupEventListeners();
    }

    private function setupEventListeners(): void
    {
        // When upload is created
        $this->server->event()->addListener('tus-server.upload.created', function (TusEvent $event) {
            $fileMeta = $event->getFile()->details();
            $tusId = basename($fileMeta['location']);

            $metadata = $fileMeta['metadata'] ?? [];
            $originalFilename = $metadata['filename'] ?? 'unknown';
            $mimeType = $metadata['filetype'] ?? 'application/octet-stream';

            FileUpload::create([
                'filename' => Str::uuid().'_'.$originalFilename,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'file_size' => $fileMeta['size'],
                'tus_id' => $tusId,
                'path' => '',
                'status' => 'uploading',
                'bytes_uploaded' => 0,
                'metadata' => $metadata,
            ]);
        });

        // When upload is progressing
        $this->server->event()->addListener('tus-server.upload.progress', function (TusEvent $event) {
            $fileMeta = $event->getFile()->details();
            $tusId = basename($fileMeta['location']);

            $fileUpload = FileUpload::where('tus_id', $tusId)->first();
            if ($fileUpload) {
                $fileUpload->update([
                    'bytes_uploaded' => $fileMeta['offset'],
                ]);
            }
        });

        // When upload is completed
        $this->server->event()->addListener('tus-server.upload.complete', function (TusEvent $event) {
            $fileMeta = $event->getFile()->details();
            $tusId = basename($fileMeta['location']);
            $uploadedFilePath = $fileMeta['file_path'];

            $fileUpload = FileUpload::where('tus_id', $tusId)->first();
            if ($fileUpload) {
                try {
                    // Move file to MinIO
                    $minioPath = $this->moveToMinio($uploadedFilePath, $fileUpload->filename);

                    $fileUpload->update([
                        'status' => 'completed',
                        'path' => $minioPath,
                        'bytes_uploaded' => $fileUpload->file_size,
                    ]);

                    // Clean up temporary file
                    if (file_exists($uploadedFilePath)) {
                        unlink($uploadedFilePath);
                    }

                    \Log::info('File upload completed successfully', [
                        'tus_id' => $tusId,
                        'filename' => $fileUpload->filename,
                        'minio_path' => $minioPath,
                    ]);
                } catch (\Exception $e) {
                    // Mark upload as failed if MinIO upload fails
                    $fileUpload->update([
                        'status' => 'failed',
                    ]);

                    \Log::error('File upload failed during MinIO transfer', [
                        'tus_id' => $tusId,
                        'filename' => $fileUpload->filename,
                        'local_path' => $uploadedFilePath,
                        'error' => $e->getMessage(),
                    ]);

                    // Don't clean up the temporary file if MinIO upload failed
                    // This allows for manual retry or investigation
                }
            }
        });
    }

    private function moveToMinio(string $localPath, string $filename): string
    {
        $minioPath = 'uploads/'.date('Y/m/d').'/'.$filename;

        try {
            // Check if local file exists
            if (! file_exists($localPath)) {
                throw new \Exception("Local file not found: {$localPath}");
            }

            // Get file contents
            $fileContents = file_get_contents($localPath);
            if ($fileContents === false) {
                throw new \Exception("Failed to read local file: {$localPath}");
            }

            // Upload to MinIO
            $result = Storage::disk('minio')->put($minioPath, $fileContents);

            if (! $result) {
                throw new \Exception('Failed to upload file to MinIO bucket');
            }

            // Verify the file was uploaded successfully
            if (! Storage::disk('minio')->exists($minioPath)) {
                throw new \Exception('File upload to MinIO succeeded but file verification failed');
            }

            \Log::info('File successfully uploaded to MinIO', [
                'local_path' => $localPath,
                'minio_path' => $minioPath,
                'filename' => $filename,
            ]);

            return $minioPath;

        } catch (\Exception $e) {
            \Log::error('Failed to upload file to MinIO', [
                'local_path' => $localPath,
                'minio_path' => $minioPath,
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw the exception so the calling code can handle it
            throw $e;
        }
    }

    public function getServer(): TusServer
    {
        return $this->server;
    }

    public function handleRequest(): void
    {
        try {
            $response = $this->server->serve();
            $response->send();
        } catch (\Exception $e) {
            \Log::error('TUS Server Error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
        exit;
    }

    public function createBucket(): bool
    {
        try {
            $disk = Storage::disk('minio');

            // Check if bucket exists by trying to list files
            // If no files exist in the test directory, we'll consider bucket as not existing
            $files = $disk->files('test');

            // Return true only if there are files in the test directory
            return count($files) > 0;
        } catch (\Exception $e) {
            // If listing fails, bucket might not exist
            // MinIO will create bucket automatically when first file is uploaded
            return false;
        }
    }
}
