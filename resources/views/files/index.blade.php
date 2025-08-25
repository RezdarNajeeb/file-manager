<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>File Upload - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen">
<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">
        <h1 class="text-3xl font-bold text-gray-900 mb-8">File Upload & Management</h1>

        <!-- Upload Section -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Upload Files</h2>
            <div id="uppy-dashboard" class="min-h-[300px]"></div>
        </div>

        <!-- Files List Section -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold text-gray-800">Uploaded Files</h2>
                <button
                    id="refresh-files"
                    class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors"
                >
                    Refresh
                </button>
            </div>

            <!-- Files Table -->
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            File Name
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Size
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Status
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Progress
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Uploaded
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                    </thead>
                    <tbody id="files-table-body" class="bg-white divide-y divide-gray-200">
                    @forelse($files as $file)
                        <tr data-file-id="{{ $file->id }}">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ $file->original_filename }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $file->formatted_file_size }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            @if($file->status === 'completed') bg-green-100 text-green-800
                                            @elseif($file->status === 'uploading') bg-yellow-100 text-yellow-800
                                            @else bg-red-100 text-red-800 @endif">
                                            {{ ucfirst($file->status) }}
                                        </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                                         style="width: {{ $file->upload_progress }}%">
                                    </div>
                                </div>
                                <span class="text-xs text-gray-500 mt-1">{{ number_format($file->upload_progress, 1) }}%</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {{ $file->created_at->format('M d, Y H:i') }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                @if($file->isCompleted())
                                    <a href="{{ route('files.download', $file) }}"
                                       class="text-blue-600 hover:text-blue-900 mr-3">
                                        Download
                                    </a>
                                @endif
                                <button
                                    onclick="deleteFile({{ $file->id }})"
                                    class="text-red-600 hover:text-red-900">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                No files uploaded yet
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if($files->hasPages())
                <div class="mt-6">
                    {{ $files->links() }}
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg p-6 max-w-sm mx-4">
        <div class="flex items-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
            <span class="ml-3 text-gray-700">Processing...</span>
        </div>
    </div>
</div>

<script>
    // CSRF Token setup
    window.Laravel = {
        csrfToken: '{{ csrf_token() }}'
    };

    // Routes
    window.routes = {
        tusUpload: '{{ route("tus.upload") }}',
        filesList: '{{ route("files.list") }}',
        filesDestroy: '{{ route("files.destroy", ":id") }}',
        uploadProgress: '{{ route("upload.progress", ":tusId") }}'
    };
</script>
</body>
</html>
