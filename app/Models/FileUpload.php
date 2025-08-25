<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FileUpload extends Model
{
    use HasFactory;

    protected $fillable = [
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

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
        'bytes_uploaded' => 'integer',
    ];

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isUploading(): bool
    {
        return $this->status === 'uploading';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function getUploadProgressAttribute(): float
    {
        if ($this->file_size === 0) {
            return 0;
        }

        return ($this->bytes_uploaded / $this->file_size) * 100;
    }

    public function getFormattedFileSizeAttribute(): string
    {
        return $this->formatBytes($this->file_size);
    }

    public function getFormattedBytesUploadedAttribute(): string
    {
        return $this->formatBytes($this->bytes_uploaded);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }
}
