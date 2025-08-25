<?php

namespace App\Services;

use App\Models\FileUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            try {
                $fileMeta = $event->getFile()->details();
                $tusId = basename($fileMeta['location']);

                $metadata = $fileMeta['metadata'] ?? [];
                $originalFilename = $metadata['filename'] ?? 'unknown';
                $mimeType = $metadata['filetype'] ?? 'application/octet-stream';

                // Use database transaction for consistency
                DB::transaction(function () use ($tusId, $originalFilename, $mimeType, $fileMeta, $metadata) {
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

                Log::info('Upload created', [
                    'tus_id' => $tusId,
                    'filename' => $originalFilename,
                    'file_size' => $fileMeta['size'],
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to create upload record', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });

        // When upload is progressing
        $this->server->event()->addListener('tus-server.upload.progress', function (TusEvent $event) {
            try {
                $fileMeta = $event->getFile()->details();
                $tusId = basename($fileMeta['location']);

                $fileUpload = FileUpload::where('tus_id', $tusId)->first();
                if ($fileUpload) {
                    $fileUpload->update([
                        'bytes_uploaded' => $fileMeta['offset'],
                    ]);

                    Log::debug('Upload progress updated', [
                        'tus_id' => $tusId,
                        'bytes_uploaded' => $fileMeta['offset'],
                        'total_size' => $fileUpload->file_size,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to update upload progress', [
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // When upload is completed
        $this->server->event()->addListener('tus-server.upload.complete', function (TusEvent $event) {
            try {
                $fileMeta = $event->getFile()->details();
                $tusId = basename($fileMeta['location']);
                $uploadedFilePath = $fileMeta['file_path'];

                Log::info('Upload completed, starting MinIO transfer', [
                    'tus_id' => $tusId,
                    'local_path' => $uploadedFilePath,
                ]);

                $fileUpload = FileUpload::where('tus_id', $tusId)->first();
                if (!$fileUpload) {
                    Log::error('FileUpload record not found for completed upload', [
                        'tus_id' => $tusId,
                    ]);
                    return;
                }

                // Use database transaction for consistency
                DB::transaction(function () use ($fileUpload, $uploadedFilePath, $tusId) {
                    try {
                        // Move file to MinIO
                        $minioPath = $this->moveToMinio($uploadedFilePath, $fileUpload->filename);

                        $fileUpload->update([
                            'status' => 'completed',
                            'path' => $minioPath,
                            'bytes_uploaded' => $fileUpload->file_size,
                        ]);

                        // Clean up temporary file only after successful MinIO upload
                        if (file_exists($uploadedFilePath)) {
                            unlink($uploadedFilePath);
                        }

                        Log::info('File upload completed successfully', [
                            'tus_id' => $tusId,
                            'filename' => $fileUpload->filename,
                            'minio_path' => $minioPath,
                        ]);

                    } catch (\Exception $e) {
                        // Mark upload as failed if MinIO upload fails
                        $fileUpload->update([
                            'status' => 'failed',
                        ]);

                        Log::error('File upload failed during MinIO transfer', [
                            'tus_id' => $tusId,
                            'filename' => $fileUpload->filename,
                            'local_path' => $uploadedFilePath,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        // Re-throw to trigger outer catch
                        throw $e;
                    }
                });

            } catch (\Exception $e) {
                Log::error('Upload completion handler failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        });
    }

    private function moveToMinio(string $localPath, string $filename): string
    {
        $minioPath = $filename;

        try {
            // Check if local file exists
            if (!file_exists($localPath)) {
                throw new \Exception("Local file not found: {$localPath}");
            }

            // Get file info
            $fileSize = filesize($localPath);
            if ($fileSize === false) {
                throw new \Exception("Cannot determine file size: {$localPath}");
            }

            Log::info('Starting MinIO upload', [
                'local_path' => $localPath,
                'minio_path' => $minioPath,
                'file_size' => $fileSize,
            ]);

            // Upload to MinIO using stream for better memory efficiency
            $stream = fopen($localPath, 'r');
            if ($stream === false) {
                throw new \Exception("Failed to open file stream: {$localPath}");
            }

            try {
                $result = Storage::disk('minio')->writeStream($minioPath, $stream);
                if (!$result) {
                    throw new \Exception('Failed to upload file to MinIO bucket');
                }
            } finally {
                fclose($stream);
            }

            // Verify the file was uploaded successfully
            if (!Storage::disk('minio')->exists($minioPath)) {
                throw new \Exception('File upload to MinIO succeeded but file verification failed');
            }

            // Verify file size matches
            $uploadedSize = Storage::disk('minio')->size($minioPath);
            if ($uploadedSize !== $fileSize) {
                throw new \Exception("File size mismatch. Local: {$fileSize}, MinIO: {$uploadedSize}");
            }

            Log::info('File successfully uploaded to MinIO', [
                'local_path' => $localPath,
                'minio_path' => $minioPath,
                'filename' => $filename,
                'file_size' => $fileSize,
            ]);

            return $minioPath;

        } catch (\Exception $e) {
            Log::error('Failed to upload file to MinIO', [
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
            Log::error('TUS Server Error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
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

            // Try to create a test file to ensure bucket exists and is writable
            $testPath = 'test/.bucket_test';
            $testContent = 'bucket test';

            $result = $disk->put($testPath, $testContent);

            if ($result) {
                // Clean up test file
                $disk->delete($testPath);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Bucket test failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
