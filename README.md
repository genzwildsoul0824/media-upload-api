# Media Upload API - Backend (Symfony 6)

RESTful API for handling chunked media file uploads with advanced features.

## Features

- ✅ Chunked upload (1MB chunks)
- ✅ Concurrency control (max 3 parallel uploads)
- ✅ File validation (MIME type + Magic Number)
- ✅ Automatic chunk cleanup (30-minute timeout)
- ✅ File deduplication (MD5 checksum)
- ✅ Organized storage (by date/user)
- ✅ Rate limiting (10 requests/minute)
- ✅ Resumable uploads
- ✅ Real-time monitoring
- ✅ Comprehensive logging

## Requirements

### Option 1: Docker (Recommended - No PHP/Composer needed)
- Docker Desktop (Windows/Mac) or Docker Engine (Linux)
- Docker Compose (included with Docker Desktop)

### Option 2: Local Installation
- PHP 8.1 or higher
- Redis Server
- Composer
- Extensions: ext-redis, ext-ctype, ext-iconv

## Quick Start with Docker

The easiest way to run the backend without installing PHP or Composer:

**⚠️ Important: Make sure Docker Desktop is running before proceeding!**

```bash
# Navigate to backend directory
cd be

# Start services (backend + Redis)
docker-compose up -d

# View logs
docker-compose logs -f

# Stop services
docker-compose down
```

The API will be available at `http://localhost:8000`

**That's it!** No PHP or Composer installation needed.

### Troubleshooting: Docker Not Running

If you see an error like:
```
error during connect: Get "http://%2F%2F.%2Fpipe%2FdockerDesktopLinuxEngine/...": 
open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.
```

**Solution:**
1. Open **Docker Desktop** application
2. Wait for it to fully start (whale icon in system tray should be steady)
3. Try the command again: `docker-compose up -d`

You can verify Docker is running with:
```bash
docker ps
```

### Docker Commands

```bash
# Build and start containers
docker-compose up -d

# View running containers
docker-compose ps

# View logs
docker-compose logs -f backend
docker-compose logs -f redis

# Execute commands in container
docker-compose exec backend php bin/console app:cleanup

# Rebuild containers after code changes
docker-compose up -d --build

# Stop containers
docker-compose stop

# Remove containers
docker-compose down
```

## Installation (Local - Without Docker)

```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Create required directories
mkdir -p var/uploads var/chunks var/cache var/log

# Set permissions
chmod -R 775 var/
```

## Configuration

Edit `.env` file to configure:

```env
# Redis connection
REDIS_URL=redis://localhost:6379

# Upload limits
UPLOAD_CHUNK_SIZE=1048576          # 1MB chunks
UPLOAD_MAX_FILE_SIZE=524288000     # 500MB max file
UPLOAD_CHUNK_TIMEOUT=1800          # 30 minutes
UPLOAD_FILE_RETENTION_DAYS=30      # Keep files for 30 days
UPLOAD_RATE_LIMIT=10               # 10 requests/minute

# Allowed file types
ALLOWED_IMAGE_TYPES="image/jpeg,image/png,image/gif,image/webp"
ALLOWED_VIDEO_TYPES="video/mp4,video/mpeg,video/quicktime,video/webm"
```

## Running the Server

### With Docker (Recommended)
```bash
cd be
docker-compose up -d
```
API available at: `http://localhost:8000`

### Local Development (Without Docker)
```bash
# Using Symfony CLI
symfony server:start

# Or using PHP built-in server
php -S localhost:8000 -t public/
```

### Production
Configure with Nginx or Apache. See `docs/deployment.md` for details.

## API Endpoints

### 1. Initiate Upload
**POST** `/api/upload/initiate`

Request:
```json
{
  "filename": "video.mp4",
  "file_size": 52428800,
  "mime_type": "video/mp4",
  "total_chunks": 50,
  "md5": "5d41402abc4b2a76b9719d911017c592"
}
```

Response:
```json
{
  "upload_id": "upload_123456",
  "message": "Upload initiated successfully"
}
```

### 2. Upload Chunk
**POST** `/api/upload/chunk`

Form Data:
- `upload_id`: Upload session ID
- `chunk_index`: Chunk index (0-based)
- `chunk`: File chunk (binary)

Response:
```json
{
  "message": "Chunk uploaded successfully",
  "chunk_index": 0,
  "progress": 2.0,
  "uploaded_chunks": 1,
  "total_chunks": 50
}
```

### 3. Finalize Upload
**POST** `/api/upload/finalize`

Request:
```json
{
  "upload_id": "upload_123456",
  "user_id": "user_789"
}
```

Response:
```json
{
  "message": "Upload completed successfully",
  "file_path": "/var/uploads/2024/01/15/user_789/video_abc123.mp4",
  "filename": "video.mp4",
  "file_size": 52428800,
  "md5": "5d41402abc4b2a76b9719d911017c592",
  "is_duplicate": false
}
```

### 4. Get Upload Status
**GET** `/api/upload/status/{uploadId}`

Response:
```json
{
  "upload_id": "upload_123456",
  "filename": "video.mp4",
  "progress": 50.0,
  "uploaded_chunks": 25,
  "total_chunks": 50,
  "missing_chunks": [26, 27, 28, ...],
  "status": "initiated"
}
```

### 5. Cancel Upload
**DELETE** `/api/upload/cancel/{uploadId}`

Response:
```json
{
  "message": "Upload cancelled successfully"
}
```

### 6. Monitoring Stats
**GET** `/api/monitoring/stats`

Response:
```json
{
  "storage": {
    "total_size": 1073741824,
    "total_size_mb": 1024.0,
    "file_count": 150
  },
  "active_uploads": 5,
  "metrics": {
    "total_uploads": 1000,
    "successful_uploads": 950,
    "success_rate": 95.0
  }
}
```

### 7. Health Check
**GET** `/api/monitoring/health`

Response:
```json
{
  "status": "healthy",
  "services": {
    "redis": "ok",
    "storage": "ok"
  }
}
```

## Cleanup Tasks

### With Docker
```bash
# Run cleanup command in container
docker-compose exec backend php bin/console app:cleanup
```

### Local Installation
```bash
php bin/console app:cleanup
```

### Automated Cleanup (Cron)

#### With Docker
Add to your crontab:
```cron
# Run cleanup every hour
0 * * * * cd /path/to/project/be && docker-compose exec -T backend php bin/console app:cleanup
```

#### Local Installation
```cron
# Run cleanup every hour
0 * * * * cd /path/to/project/be && php bin/console app:cleanup
```

## Testing

```bash
# Run all tests
vendor/bin/phpunit

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/
```

## Logging

Logs are stored in `var/log/`:
- `dev.log` - General application logs
- `upload.log` - Upload-specific logs
- `security.log` - Security-related logs

Log levels: DEBUG, INFO, WARN, ERROR

## Security Features

- File type validation (MIME + Magic Number)
- Rate limiting (per IP)
- Chunk timeout (automatic cleanup)
- Input sanitization
- Error message sanitization

## Performance

- Chunk upload latency: <300ms
- File reassembly speed: ≥50MB/s
- Max concurrent uploads: 3 per client
- Redis-backed state management

## Troubleshooting

### Docker Issues

**Docker Desktop not running:**
```bash
# Check if Docker is running
docker ps

# If you get connection error, start Docker Desktop application
# Wait for Docker Desktop to fully start, then try again
```

**Containers won't start:**
```bash
# Check Docker is running
docker ps

# View detailed logs
docker-compose logs backend
docker-compose logs redis

# Rebuild containers
docker-compose up -d --build
```

**Port already in use:**
```bash
# Change port in docker-compose.yml
ports:
  - "8001:8000"  # Use port 8001 instead
```

**Permission errors (Linux/Mac):**
```bash
# Fix permissions on host directories
sudo chown -R $USER:$USER var/
chmod -R 775 var/
```

### Redis Connection Issues

**With Docker:**
```bash
# Check Redis container is running
docker-compose ps redis

# Test Redis connection from backend container
docker-compose exec backend php -r "echo (new Predis\Client('redis://redis:6379'))->ping();"
```

**Local Installation:**
```bash
# Check Redis is running
redis-cli ping

# Should return: PONG
```

### Permission Issues
```bash
# Fix directory permissions
chmod -R 775 var/
chown -R www-data:www-data var/
```

### Storage Full
```bash
# Check disk space
df -h

# Run cleanup
php bin/console app:cleanup
```

## Future Improvements

### Testing
- Add integration tests for chunk upload flow
- Add E2E tests for complete upload lifecycle
- Add performance tests for concurrent uploads
- Add load testing for rate limiting
- Add tests for file validation edge cases
- Add tests for cleanup command
- Increase test coverage to >90%
- Add API contract testing

## License

MIT

