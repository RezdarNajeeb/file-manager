import './bootstrap'
import { Uppy, Dashboard, Tus } from './uppy-config'

class FileManager {
    constructor() {
        this.uppy = null
        this.refreshInterval = null
        this.uploadCompletionTimeout = null
        this.init()
    }

    init() {
        this.setupUppy()
        this.setupEventListeners()
        this.startPeriodicRefresh()
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
        })

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
            theme: 'dark', // Set dark theme
        })

        // Add TUS plugin for resumable uploads
        this.uppy.use(Tus, {
            endpoint: window.routes.tusUpload,
            removeFingerprintOnSuccess: false,
            chunkSize: 50 * 1024 * 1024, // 50MB chunks
            parallelUploads: 1, // Upload files one at a time for stability
            storeFingerprintForResuming: true,
            retryDelays: [0, 1000, 3000, 5000], // Retry delays in ms
            headers: {
                'X-CSRF-TOKEN': window.Laravel.csrfToken,
            },
            // Add upload completion callback
            onSuccess: (file, response) => {
                console.log('TUS upload completed:', file.name, response)
                this.scheduleRefresh()
            },
            onError: (error, file, response) => {
                console.error('TUS upload error:', error, file, response)
            }
        })

        // Event listeners for upload events
        this.uppy.on('upload', () => {
            console.log('Upload started')
            this.showLoading()
        })

        this.uppy.on('upload-success', (file, response) => {
            console.log('Upload successful:', file.name, response)
            // Schedule a delayed refresh to ensure database is updated
            this.scheduleRefresh()
        })

        this.uppy.on('upload-error', (file, error) => {
            console.error('Upload error:', file.name, error)
            this.showNotification('Upload failed: ' + error.message, 'error')
            this.hideLoading()
        })

        this.uppy.on('upload-progress', (file, progress) => {
            console.log(
                'Upload progress:',
                file.name,
                `${progress.bytesUploaded}/${progress.bytesTotal}`
            )
        })

        this.uppy.on('complete', (result) => {
            console.log('Upload complete:', result)
            this.hideLoading()

            if (result.successful.length > 0) {
                this.showNotification(
                    `Successfully uploaded ${result.successful.length} file(s)`,
                    'success'
                )
                // Force refresh after a short delay to allow server processing
                this.scheduleRefresh(3000)
            }

            if (result.failed.length > 0) {
                this.showNotification(
                    `Failed to upload ${result.failed.length} file(s)`,
                    'error'
                )
            }
        })

        // Add error handling for TUS
        this.uppy.on('error', (error) => {
            console.error('Uppy error:', error)
            this.showNotification('Upload error: ' + error.message, 'error')
            this.hideLoading()
        })

        // Add retry handling
        this.uppy.on('retry-all', () => {
            console.log('Retrying all failed uploads')
            this.showLoading()
        })
    }

    setupEventListeners() {
        // Refresh button
        document
            .getElementById('refresh-files')
            ?.addEventListener('click', () => {
                this.refreshFilesList(true) // Force refresh with cache busting
            })

        // Setup CSRF token for AJAX requests
        const token = document.querySelector('meta[name="csrf-token"]')
        if (token) {
            window.axios.defaults.headers.common['X-CSRF-TOKEN'] =
                token.getAttribute('content')
        }
    }

    startPeriodicRefresh() {
        // Refresh files list every 30 seconds to show upload progress
        this.refreshInterval = setInterval(() => {
            this.refreshFilesList()
        }, 30000)
    }

    scheduleRefresh(delay = 1500) {
        // Clear any existing scheduled refresh
        if (this.uploadCompletionTimeout) {
            clearTimeout(this.uploadCompletionTimeout)
        }

        // Schedule refresh with delay
        this.uploadCompletionTimeout = setTimeout(() => {
            this.refreshFilesList(true) // Force refresh after upload
        }, delay)
    }

    async refreshFilesList(forceCacheBust = false) {
        try {
            // Add cache busting parameter to prevent browser caching issues
            const url = new URL(window.routes.filesList, window.location.origin)
            if (forceCacheBust) {
                url.searchParams.append('_t', Date.now())
            }

            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': window.Laravel.csrfToken,
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                },
            })

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`)
            }

            const data = await response.json()
            this.updateFilesTable(data.data)

            if (forceCacheBust) {
                this.showNotification('Files list refreshed', 'success')
            }
        } catch (error) {
            console.error('Error refreshing files:', error)
            this.showNotification('Failed to refresh files list: ' + error.message, 'error')
        }
    }

    updateFilesTable(files) {
        const tbody = document.getElementById('files-table-body')
        if (!tbody) return

        if (files.length === 0) {
            tbody.innerHTML = `
                <tr class="border-gray-700">
                    <td colspan="6" class="px-6 py-8 text-center text-gray-400">
                        No files uploaded yet
                    </td>
                </tr>
            `
            return
        }

        tbody.innerHTML = files
            .map(
                (file) => `
            <tr data-file-id="${file.id}" class="border-gray-700 hover:bg-gray-800 transition-colors">
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-100">
                        ${this.escapeHtml(file.filename)}
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                    ${file.file_size}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                        ${
                    file.status === 'completed'
                        ? 'bg-green-900 text-green-200 border border-green-700'
                        : file.status === 'uploading'
                            ? 'bg-yellow-900 text-yellow-200 border border-yellow-700'
                            : 'bg-red-900 text-red-200 border border-red-700'
                }">
                        ${file.status.charAt(0).toUpperCase() + file.status.slice(1)}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="w-full bg-gray-700 rounded-full h-2">
                        <div class="bg-blue-500 h-2 rounded-full transition-all duration-300"
                             style="width: ${file.progress}%">
                        </div>
                    </div>
                    <span class="text-xs text-gray-400 mt-1">${file.progress.toFixed(1)}%</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                    ${file.created_at}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    ${
                    file.download_url
                        ? `
                        <a href="${file.download_url}"
                           class="text-blue-400 hover:text-blue-300 mr-3 transition-colors">
                            Download
                        </a>
                    `
                        : ''
                }
                    <button
                        onclick="deleteFile(${file.id})"
                        class="text-red-400 hover:text-red-300 transition-colors">
                        Delete
                    </button>
                </td>
            </tr>
        `
            )
            .join('')
    }

    escapeHtml(text) {
        const div = document.createElement('div')
        div.textContent = text
        return div.innerHTML
    }

    showNotification(message, type = 'info') {
        // Simple notification system with dark theme
        const notification = document.createElement('div')
        notification.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-1000 transition-all duration-300 transform translate-x-full ${
            type === 'success'
                ? 'bg-green-600 text-white border border-green-500'
                : type === 'error'
                    ? 'bg-red-600 text-white border border-red-500'
                    : 'bg-blue-600 text-white border border-blue-500'
        }`
        notification.textContent = message

        document.body.appendChild(notification)

        // Animate in
        setTimeout(() => {
            notification.classList.remove('translate-x-full')
        }, 100)

        // Auto remove
        setTimeout(() => {
            notification.classList.add('translate-x-full')
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification)
                }
            }, 300)
        }, 5000)
    }

    showLoading() {
        document.getElementById('loading-overlay').classList.remove('hidden')
    }

    hideLoading() {
        document.getElementById('loading-overlay').classList.add('hidden')
    }

    cleanup() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval)
        }
        if (this.uploadCompletionTimeout) {
            clearTimeout(this.uploadCompletionTimeout)
        }
        if (this.uppy) {
            this.uppy.destroy()
        }
    }
}

// Global delete function with improved error handling
window.deleteFile = async function (fileId) {
    if (!confirm('Are you sure you want to delete this file?')) {
        return
    }

    try {
        window.fileManager.showLoading()

        const response = await fetch(
            window.routes.filesDestroy.replace(':id', fileId),
            {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': window.Laravel.csrfToken,
                    'Cache-Control': 'no-cache'
                },
            }
        )

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}))
            throw new Error(errorData.message || `HTTP ${response.status}`)
        }

        // Remove the row from table
        const row = document.querySelector(`tr[data-file-id="${fileId}"]`)
        if (row) {
            row.remove()
        }

        window.fileManager.showNotification(
            'File deleted successfully',
            'success'
        )

        // Refresh the list to ensure consistency
        setTimeout(() => {
            window.fileManager.refreshFilesList(true)
        }, 500)

    } catch (error) {
        console.error('Error deleting file:', error)
        window.fileManager.showNotification('Failed to delete file: ' + error.message, 'error')
    } finally {
        window.fileManager.hideLoading()
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.fileManager = new FileManager()
})

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.fileManager) {
        window.fileManager.cleanup()
    }
})

// Add visibility change handler to refresh when tab becomes visible
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && window.fileManager) {
        // Refresh files when tab becomes visible (helps with cache issues)
        window.fileManager.refreshFilesList(true)
    }
})
