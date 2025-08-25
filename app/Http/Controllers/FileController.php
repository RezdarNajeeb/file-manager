<?php

namespace App\Http\Controllers;

use App\Models\FileUpload;
use App\Services\TusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    private TusService $tusService;

    public function __construct(TusService $tusService)
    {
        $this->tusService = $tusService;
    }

    /**
     * Display the file upload interface
     */
    public function index()
    {
        $files = FileUpload::orderBy('created_at', 'desc')->paginate(20);

        return view('files.index', compact('files'));
    }

    /**
     * Handle TUS upload requests
     */
    public function tusUpload(Request $request)
    {
        $this->tusService->handleRequest();
    }

    /**
     * Get upload progress for a specific TUS ID
     */
    public function getUploadProgress(Request $request, string $tusId)
    {
        $fileUpload = FileUpload::where('tus_id', $tusId)->first();

        if (! $fileUpload) {
            return response()->json(['error' => 'Upload not found'], 404);
        }

        return response()->json([
            'id' => $fileUpload->id,
            'tus_id' => $fileUpload->tus_id,
            'filename' => $fileUpload->original_filename,
            'file_size' => $fileUpload->file_size,
            'bytes_uploaded' => $fileUpload->bytes_uploaded,
            'status' => $fileUpload->status,
            'progress' => $fileUpload->upload_progress,
            'formatted_size' => $fileUpload->formatted_file_size,
            'formatted_uploaded' => $fileUpload->formatted_bytes_uploaded,
        ]);
    }

    /**
     * Download a file with resumable support
     */
    public function download(Request $request, FileUpload $fileUpload)
    {
        if (! $fileUpload->isCompleted()) {
            abort(404, 'File not found or upload not completed');
        }

        $disk = Storage::disk('minio');

        if (! $disk->exists($fileUpload->path)) {
            abort(404, 'File not found in storage');
        }

        return $this->createResumableDownloadResponse($disk, $fileUpload, $request);
    }

    /**
     * Create a resumable download response
     */
    private function createResumableDownloadResponse($disk, FileUpload $fileUpload, Request $request): StreamedResponse
    {
        $size = $fileUpload->file_size;
        $start = 0;
        $end = $size - 1;

        // Handle range requests for resumable downloads
        if ($request->hasHeader('Range')) {
            $range = $request->header('Range');
            if (preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
                $start = intval($matches[1]);
                if (! empty($matches[2])) {
                    $end = intval($matches[2]);
                }
            }
        }

        $length = $end - $start + 1;

        $headers = [
            'Content-Type' => $fileUpload->mime_type,
            'Content-Length' => $length,
            'Content-Disposition' => 'attachment; filename="'.$fileUpload->original_filename.'"',
            'Accept-Ranges' => 'bytes',
            'Content-Range' => "bytes $start-$end/$size",
        ];

        $status = $request->hasHeader('Range') ? 206 : 200;

        return new StreamedResponse(function () use ($disk, $fileUpload, $start, $length) {
            $stream = $disk->readStream($fileUpload->path);

            if ($start > 0) {
                fseek($stream, $start);
            }

            $bytesRemaining = $length;
            $chunkSize = 8192; // 8KB chunks

            while ($bytesRemaining > 0 && ! feof($stream)) {
                $bytesToRead = min($chunkSize, $bytesRemaining);
                $chunk = fread($stream, $bytesToRead);

                if ($chunk === false) {
                    break;
                }

                echo $chunk;
                flush();

                $bytesRemaining -= strlen($chunk);
            }

            fclose($stream);
        }, $status, $headers);
    }

    /**
     * Delete a file
     */
    public function destroy(FileUpload $fileUpload)
    {
        if ($fileUpload->isCompleted()) {
            $disk = Storage::disk('minio');
            if ($disk->exists($fileUpload->path)) {
                $disk->delete($fileUpload->path);
            }
        }

        $fileUpload->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get files as JSON for AJAX requests
     */
    public function getFiles(Request $request)
    {
        $files = FileUpload::orderBy('created_at', 'desc')->paginate(20);

        $files->getCollection()->transform(function ($file) {
            return [
                'id' => $file->id,
                'filename' => $file->original_filename,
                'file_size' => $file->formatted_file_size,
                'status' => $file->status,
                'progress' => $file->upload_progress,
                'created_at' => $file->created_at->format('M d, Y H:i'),
                'download_url' => $file->isCompleted() ? route('files.download', $file) : null,
            ];
        });

        return response()->json($files);
    }
}
