import './bootstrap';
import { Uppy, Dashboard, Tus } from './uppy-config';

class FileManager {
    constructor() {
        this.uppy = null;
        this.refreshInterval = null;
        this.init();
    }

    init() {
        this.setupUppy();
        this.setupEventListeners();
        this.startPeriodicRefresh();
    }

    setupUppy() {
        // Initialize Uppy
        this.uppy = new Uppy({
            restrictions: {
                maxFileSize: 5 * 1024 * 1024 * 1024, // 5GB
                allowedFileTypes: null, // Allow all file types
            },
            autoProceed: false,
            debug: true, // Enable debug mode
        });

        // Add Dashboard plugin
        this.uppy.use(Dashboard, {
            target: '#uppy-dashboard',
            inline: true,
            width: '100%',
            height: 300,
            showProgressDetails: true,
            showRemoveButtonAfterComplete: true,
            showSelectedFiles: true,
            proudlyDisplayPoweredByUppy: false,
        });

        // Add TUS plugin for resumable uploads
        this.uppy.use(Tus, {
            endpoint: window.routes.tusUpload,
            removeFingerprintOnSuccess: false,
            chunkSize: 1024 * 1024, // 1MB chunks for better resumability
            parallelUploads: 1, // Upload files one at a time for stability
            storeFingerprintForResuming: true, // Store fingerprints to enable resume
            headers: {
                'X-CSRF-TOKEN': window.Laravel.csrfToken,
            },
        });

        // Event listeners for upload events
        this.uppy.on('upload', () => {
            console.log('Upload started');
        });

        this.uppy.on('upload-success', (file, response) => {
            console.log('Upload successful:', file.name, response);
            this.refreshFilesList();
        });

        this.uppy.on('upload-error', (file, error) => {
            console.error('Upload error:', file.name, error);
            this.showNotification('Upload failed: ' + error.message, 'error');
        });

        this.uppy.on('upload-progress', (file, progress) => {
            // Update progress in real-time if needed
            console.log('Upload progress:', file.name, `${progress.bytesUploaded}/${progress.bytesTotal}`);
        });

        this.uppy.on('complete', (result) => {
            console.log('Upload complete:', result);
            if (result.successful.length > 0) {
                this.showNotification(
                    `Successfully uploaded ${result.successful.length} file(s)`,
                    'success'
                );
            }
        });

        // Add error handling for TUS
        this.uppy.on('error', (error) => {
            console.error('Uppy error:', error);
            this.showNotification('Upload error: ' + error.message, 'error');
        });

        // Add retry handling
        this.uppy.on('retry-all', () => {
            console.log('Retrying all failed uploads');
        });
    }

    setupEventListeners() {
        // Refresh button
        document.getElementById('refresh-files')?.addEventListener('click', () => {
            this.refreshFilesList();
        });

        // Setup CSRF token for AJAX requests
        const token = document.querySelector('meta[name="csrf-token"]');
        if (token) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.getAttribute('content');
        }
    }

    startPeriodicRefresh() {
        // Refresh files list every 30 seconds to show upload progress
        this.refreshInterval = setInterval(() => {
            this.refreshFilesList();
        }, 30000);
    }

    async refreshFilesList() {
        try {
            const response = await fetch(window.routes.filesList, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': window.Laravel.csrfToken,
                },
            });

            if (!response.ok) {
                throw new Error('Failed to fetch files');
            }

            const data = await response.json();
            this.updateFilesTable(data.data);
        } catch (error) {
            console.error('Error refreshing files:', error);
            this.showNotification('Failed to refresh files list', 'error');
        }
    }

    updateFilesTable(files) {
        const tbody = document.getElementById('files-table-body');
        if (!tbody) return;

        if (files.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                        No files uploaded yet
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = files.map(file => `
            <tr data-file-id="${file.id}">
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900">
                        ${this.escapeHtml(file.filename)}
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    ${file.file_size}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                        ${file.status === 'completed' ? 'bg-green-100 text-green-800' :
            file.status === 'uploading' ? 'bg-yellow-100 text-yellow-800' :
                'bg-red-100 text-red-800'}">
                        ${file.status.charAt(0).toUpperCase() + file.status.slice(1)}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                             style="width: ${file.progress}%">
                        </div>
                    </div>
                    <span class="text-xs text-gray-500 mt-1">${file.progress.toFixed(1)}%</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    ${file.created_at}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    ${file.download_url ? `
                        <a href="${file.download_url}"
                           class="text-blue-600 hover:text-blue-900 mr-3">
                            Download
                        </a>
                    ` : ''}
                    <button
                        onclick="deleteFile(${file.id})"
                        class="text-red-600 hover:text-red-900">
                        Delete
                    </button>
                </td>
            </tr>
        `).join('');
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    showNotification(message, type = 'info') {
        // Simple notification system
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 transition-all duration-300 transform translate-x-full ${
            type === 'success' ? 'bg-green-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                    'bg-blue-500 text-white'
        }`;
        notification.textContent = message;

        document.body.appendChild(notification);

        // Animate in
        setTimeout(() => {
            notification.classList.remove('translate-x-full');
        }, 100);

        // Auto remove
        setTimeout(() => {
            notification.classList.add('translate-x-full');
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 300);
        }, 5000);
    }

    showLoading() {
        document.getElementById('loading-overlay').classList.remove('hidden');
    }

    hideLoading() {
        document.getElementById('loading-overlay').classList.add('hidden');
    }

    cleanup() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
        if (this.uppy) {
            this.uppy.destroy();
        }
    }
}

// Global delete function
window.deleteFile = async function(fileId) {
    if (!confirm('Are you sure you want to delete this file?')) {
        return;
    }

    try {
        const response = await fetch(window.routes.filesDestroy.replace(':id', fileId), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.Laravel.csrfToken,
            },
        });

        if (!response.ok) {
            throw new Error('Failed to delete file');
        }

        // Remove the row from table
        const row = document.querySelector(`tr[data-file-id="${fileId}"]`);
        if (row) {
            row.remove();
        }

        window.fileManager.showNotification('File deleted successfully', 'success');
    } catch (error) {
        console.error('Error deleting file:', error);
        window.fileManager.showNotification('Failed to delete file', 'error');
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.fileManager = new FileManager();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.fileManager) {
        window.fileManager.cleanup();
    }
});
