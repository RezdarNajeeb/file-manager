<?php

use App\Http\Controllers\DebugController;
use App\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [FileController::class, 'index'])->name('files.index');

// Debug route for testing TUS endpoint
Route::get('/debug/tus', [DebugController::class, 'testTus'])->name('debug.tus');

// File management routes
Route::controller(FileController::class)->group(function () {
    Route::get('/files', 'getFiles')->name('files.list');
    Route::get('/files/{fileUpload}/download', 'download')->name('files.download');
    Route::delete('/files/{fileUpload}', 'destroy')->name('files.destroy');
    Route::get('/upload/progress/{tusId}', 'getUploadProgress')->name('upload.progress');
});

// TUS upload endpoint with CORS middleware
Route::any('/tus/{path?}', [FileController::class, 'tusUpload'])
    ->middleware(['web', App\Http\Middleware\TusCors::class])
    ->where('path', '.*')
    ->name('tus.upload');
