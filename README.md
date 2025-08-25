# Laravel Resumable File Upload System

A robust Laravel application for handling large file uploads with resumable capabilities using TUS protocol and MinIO object storage. Built with modern web technologies and designed for production use.

## Features

- **Resumable Uploads**: Uses TUS protocol for reliable, resumable file transfers
- **Large File Support**: Handles files up to 5GB with chunked uploads
- **MinIO Storage**: Scalable object storage backend compatible with S3
- **Real-time Progress**: Live upload progress tracking with WebSocket-like updates
- **Download Resume**: Supports HTTP range requests for resumable downloads
- **File Management**: Complete CRUD operations for uploaded files
- **Database Sync**: Command to synchronize files between MinIO and database
- **Modern UI**: Dark-themed responsive interface built with Tailwind CSS
- **Testing Suite**: Comprehensive test coverage for all major features

## Tech Stack

- **Backend**: Laravel 12.x, PHP 8.2+
- **Storage**: MinIO (S3-compatible object storage)
- **Database**: MySQL 8.0
- **Frontend**: JavaScript ES6+, Uppy.js for uploads, Tailwind CSS 4.x
- **Upload Protocol**: TUS (Resumable Upload Protocol)
- **Development**: Laravel Sail, Docker Compose
- **Code Quality**: PHPStan, Laravel Pint, ESLint, Prettier

## Quick Start

### Prerequisites

- Docker and Docker Compose
- PHP 8.2+ (for local development)
- Node.js 18+ and npm

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd laravel-file-upload-system
   ```

2. **Install dependencies**
   ```bash
   # Install PHP dependencies
   composer install
   
   # Install Node.js dependencies
   npm install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure environment variables**
   ```env
   # Database
   DB_DATABASE=your_database_name
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   
   # MinIO Configuration
   MINIO_ROOT_USER=minioadmin
   MINIO_ROOT_PASSWORD=minioadmin
   MINIO_ENDPOINT=http://localhost:9000
   MINIO_ACCESS_KEY=minioadmin
   MINIO_SECRET_KEY=minioadmin
   MINIO_BUCKET=uploads
   ```

5. **Start the application**
   ```bash
   # Start all services (Laravel, MySQL, MinIO)
   ./vendor/bin/sail up -d
   
   # Run database migrations
   ./vendor/bin/sail artisan migrate
   
   # Build frontend assets
   ./vendor/bin/sail npm run build
   
   # For development with hot reload
   ./vendor/bin/sail npm run dev
   ```

6. **Access the application**
    - **Main App**: http://localhost
    - **MinIO Console**: http://localhost:9001 (minioadmin/minioadmin)
    - **Database**: localhost:3306

## Usage

### File Upload

1. Navigate to the main page
2. Drag and drop files or click to select
3. Files will upload with resumable capability
4. Monitor real-time progress in the dashboard
5. Completed files appear in the files table

### File Management

- **Download**: Click download link for completed files
- **Delete**: Remove files from both database and storage
- **Sync**: Use the sync command to reconcile storage and database

### API Endpoints

```
GET  /                          # Main upload interface
GET  /files                     # JSON list of files
GET  /files/{id}/download       # Download file (with resume support)
DELETE /files/{id}              # Delete file
POST /tus                       # TUS upload endpoint
GET  /upload/progress/{tusId}   # Upload progress by TUS ID
```

## Development

### Running Tests

```bash
# Run all tests
./vendor/bin/sail test

# Run specific test types
./vendor/bin/sail test --testsuite=Feature
./vendor/bin/sail test --testsuite=Unit

# Run with coverage
./vendor/bin/sail test --coverage
```

### Code Quality

```bash
# Format PHP code
./vendor/bin/sail pint

# Run static analysis
./vendor/bin/sail php ./vendor/bin/phpstan analyse

# Lint JavaScript
./vendor/bin/sail npx eslint resources/js/

# Format JavaScript
./vendor/bin/sail npx prettier --write resources/js/
```

### Development Commands

```bash
# Start development environment with hot reload
composer run dev

# Sync files between MinIO and database
./vendor/bin/sail artisan files:sync

# Clear application cache
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan view:clear
```

## Architecture

### File Upload Flow

1. **Client** uploads file using Uppy.js with TUS protocol
2. **TUS Server** handles chunked upload to temporary storage
3. **Event Listeners** create database records and track progress
4. **Completion Handler** moves files to MinIO and updates status
5. **Database** maintains file metadata and sync state

### Storage Structure

```
MinIO Bucket (uploads/)
├── uuid_filename1.ext
├── uuid_filename2.ext
└── ...

Database (file_uploads)
├── id, filename, original_filename
├── path, file_size, mime_type
├── status, bytes_uploaded
└── tus_id, metadata, timestamps
```

### Key Components

- **TusService**: Handles TUS protocol and MinIO integration
- **FileController**: REST API for file management
- **FileUpload Model**: Database representation with helpers
- **SyncFilesCommand**: Reconciles storage and database
- **Frontend**: Uppy.js integration with real-time updates

## Configuration

### File Upload Limits

Configure in `docker/php/php.ini`:
```ini
upload_max_filesize = 5G
post_max_size = 5G
memory_limit = 512M
max_execution_time = 3600
```

### TUS Configuration

Settings in `TusService.php`:
- Chunk size: 50MB
- Max file size: 5GB
- Parallel uploads: 1 (for stability)
- Retry attempts: 4 with exponential backoff

### MinIO Configuration

Bucket policies and access are configured in docker-compose.yml and environment variables.

## Monitoring

### Logs

```bash
# Application logs
./vendor/bin/sail logs

# Real-time log monitoring
./vendor/bin/sail artisan pail

# MinIO logs
docker logs <minio-container-name>
```

### Health Checks

The application includes health checks for:
- Database connectivity
- MinIO bucket access
- TUS server status
- File system permissions

## Troubleshooting

### Common Issues

**Upload fails with "413 Request Entity Too Large"**
- Check nginx/Apache client_max_body_size
- Verify PHP upload limits in php.ini

**Files not appearing after upload**
- Run `artisan files:sync` to reconcile storage
- Check MinIO bucket permissions
- Verify database connection

**MinIO connection errors**
- Ensure MinIO is running: `docker ps`
- Check environment variables in .env
- Verify network connectivity between containers

**TUS resumption fails**
- Clear TUS cache: `rm -rf storage/app/tus-cache/*`
- Check file permissions on storage directories
- Verify CSRF token configuration

### Performance Tuning

For high-volume usage:
- Increase PHP memory limits and execution time
- Configure Redis for session/cache storage
- Use queue workers for file processing
- Implement CDN for file downloads
- Monitor disk space and implement cleanup policies

## Security Considerations

- Files are stored with UUID prefixes to prevent enumeration
- CSRF protection on all endpoints
- File type validation (configurable)
- Size limits enforced at multiple layers
- MinIO access keys should be rotated regularly
- Consider virus scanning for uploaded files

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make changes with tests
4. Run code quality checks
5. Submit a pull request

### Pre-commit Hooks

The project uses Husky for pre-commit hooks:
- PHP code formatting with Pint
- Static analysis with PHPStan
- JavaScript linting with ESLint
- Code formatting with Prettier

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).

## Support

For issues and questions:
1. Check the troubleshooting section
2. Review existing issues in the repository
3. Create a new issue with detailed information
4. Include logs and environment details

---

Built with ❤️ using Laravel, TUS Protocol, and MinIO
