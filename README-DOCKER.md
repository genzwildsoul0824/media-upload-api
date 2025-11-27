# Docker Setup Guide

Complete guide for running the backend with Docker - **No PHP or Composer installation needed!**

## Prerequisites

- **Docker Desktop** (Windows/Mac) or **Docker Engine** (Linux)
- **Docker Compose** (usually included with Docker Desktop)

## Quick Start

```bash
cd be
docker-compose up -d
```

That's it! The API will be available at `http://localhost:8000`

## What Gets Started

1. **Backend Container** - PHP 8.1 with Symfony 6, all dependencies pre-installed
2. **Redis Container** - For chunk tracking and caching

## Common Commands

### Start Services
```bash
docker-compose up -d
```

### Stop Services
```bash
docker-compose stop
```

### Stop and Remove Containers
```bash
docker-compose down
```

### View Logs
```bash
# All services
docker-compose logs -f

# Just backend
docker-compose logs -f backend

# Just Redis
docker-compose logs -f redis
```

### Rebuild After Code Changes
```bash
docker-compose up -d --build
```

### Execute Commands in Container
```bash
# Run cleanup command
docker-compose exec backend php bin/console app:cleanup

# Access shell
docker-compose exec backend bash

# Check PHP version
docker-compose exec backend php -v
```

## Configuration

### Environment Variables

Edit `docker-compose.yml` to customize settings:

```yaml
environment:
  - UPLOAD_MAX_FILE_SIZE=1048576000  # 1GB
  - UPLOAD_RATE_LIMIT=20              # 20 requests/minute
```

Or create a `.env` file in the `be/` directory:

```env
UPLOAD_MAX_FILE_SIZE=1048576000
UPLOAD_RATE_LIMIT=20
```

### Port Configuration

Change the port mapping in `docker-compose.yml`:

```yaml
ports:
  - "8001:8000"  # Use port 8001 on host
```

## Data Persistence

### Uploaded Files
- **Location**: `be/var/uploads/`
- **Persisted**: Yes, via Docker volume
- **Survives**: Container restarts

### Chunk Files
- **Location**: `be/var/chunks/`
- **Persisted**: Yes, via Docker volume
- **Auto-cleaned**: After 30 minutes

### Redis Data
- **Stored in**: Docker volume `redis_data`
- **Persisted**: Yes, across container restarts
- **Remove**: Use `docker-compose down -v`

### Logs
- **Location**: `be/var/log/`
- **Persisted**: Yes, via Docker volume
- **Rotation**: Daily, kept for 30 days

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker-compose logs backend

# Rebuild from scratch
docker-compose down -v
docker-compose up -d --build
```

### Permission Issues

**Linux/Mac:**
```bash
sudo chown -R $USER:$USER var/
chmod -R 775 var/
```

**Windows:**
- Ensure Docker Desktop has access to the project directory
- Check Windows file sharing settings

### Port Already in Use

```bash
# Windows
netstat -ano | findstr :8000

# Linux/Mac
lsof -i :8000

# Change port in docker-compose.yml
ports:
  - "8001:8000"
```

### Redis Connection Errors

```bash
# Check Redis is running
docker-compose ps redis

# Test connection
docker-compose exec backend php -r "echo (new Predis\Client('redis://redis:6379'))->ping();"
```

### Out of Disk Space

```bash
# Clean up Docker
docker system prune -a

# Remove unused volumes
docker volume prune
```

## Development Workflow

### Making Code Changes

1. Edit code in your IDE
2. Changes are reflected immediately (volumes are mounted)
3. For PHP config changes, restart:
   ```bash
   docker-compose restart backend
   ```

### Running Tests

```bash
# Run tests in container
docker-compose exec backend vendor/bin/phpunit

# With coverage
docker-compose exec backend vendor/bin/phpunit --coverage-html coverage/
```

### Installing New Dependencies

```bash
# Add to composer.json, then:
docker-compose exec backend composer install

# Or rebuild
docker-compose up -d --build
```

## Health Check

After starting, verify it's working:

```bash
curl http://localhost:8000/api/monitoring/health
```

Or open in browser: `http://localhost:8000/api/monitoring/health`

Expected response:
```json
{
  "status": "healthy",
  "services": {
    "redis": "ok",
    "storage": "ok"
  }
}
```

## Next Steps

- See main [README.md](README.md) for API documentation
- Check API endpoints at `http://localhost:8000/api`
- View monitoring stats at `http://localhost:8000/api/monitoring/stats`

