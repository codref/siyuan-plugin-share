# Siyuan Note Share Service - Docker Deployment Guide

This document explains how to quickly deploy the Siyuan Note Share service using Docker Compose, as an alternative to traditional deployment methods like BT-Panel.

## 📋 Prerequisites

- Docker 20.10+
- Docker Compose 1.29+
- git

Install Docker:
```bash
# macOS (using Homebrew)
brew install --cask docker

# Linux (Ubuntu/Debian)
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER

# Or visit https://docs.docker.com/get-docker/
```

## 🚀 Quick Start

### 1. Get the Source Code

Fetch the source files needed to set up the website.

```bash
# Clone the repository. If you don't use Git, you can also download the repository as a zip archive, extract it, and place it in the current directory — the result is the same.
git clone https://github.com/b8l8u8e8/siyuan-plugin-share.git
```

### 2. Prepare the Configuration File

First, copy the example configuration file (required):

```bash
# Replace /Users/quxiaopang/ below with your own path
cd /Users/quxiaopang/siyuan-plugin-share
cp php-site/config.example.php php-site/config.php
```

Edit `php-site/config.php` and adjust settings as needed (optional):

```php
<?php
return [
    'app_name' => 'Siyuan Note Share',
    'allow_registration' => true,
    'default_storage_limit_mb' => 1024,
    // ... other settings
];
```

### 3. Build and Start the Service

```bash
# Build the Docker image
docker-compose build

# Start the service (in the background)
docker-compose up -d

# View logs
docker-compose logs -f
```

### 4. Access the Application

Open your browser and go to: **http://localhost:8080**

Default admin credentials:
- Username: `admin`
- Password: `123456` (you will be prompted to change it on first login)

## 📁 Directory Structure

```
siyuan-plugin-share/
├── docker-compose.yml          # Docker Compose configuration
├── .dockerignore              # Docker ignore file
└── php-site/
    ├── Dockerfile             # PHP application container configuration
    ├── config.php            # App configuration (must be created manually)
    ├── config.example.php    # Example configuration
    ├── storage/              # SQLite database (created automatically)
    └── uploads/              # User-uploaded files (created automatically)
```

## 🔧 Common Commands

```bash
# Check service status
docker-compose ps

# View logs
docker-compose logs -f web

# Restart the service
docker-compose restart

# Stop the service
docker-compose stop

# Stop and remove containers
docker-compose down

# Rebuild the image
docker-compose build --no-cache

# Enter the container shell
docker-compose exec web sh
```

## 🔄 Updating the Application

```bash
# Pull the latest source code using Git.
# If you don't use Git, download the latest zip archive and overwrite the current source directory.
#
# If a conflict is reported during pull, it means:
# a file you modified locally was also updated in the remote repository.
# Git will ask you to resolve the conflict before proceeding,
# to prevent your local changes from being silently overwritten.
#
# This is normal behavior, not an error.
# Resolve the conflicts as prompted, then run git pull again.
git pull

# Stop the service
docker-compose down

# Rebuild the image
docker-compose build

# Start the service
docker-compose up -d
```

## 💾 Data Backup

All important data is stored in the following directories. Back them up regularly:

```bash
# Back up the database and uploaded files
tar -czf backup-$(date +%Y%m%d).tar.gz \
    php-site/storage \
    php-site/uploads \
    php-site/config.php

# Restore a backup
tar -xzf backup-20260114.tar.gz
```

## 🌐 Production Deployment

### Using Nginx as a Reverse Proxy

If you need a custom domain and HTTPS, it is recommended to add an Nginx layer in front:

```nginx
server {
    listen 80;
    server_name share.yourdomain.com;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Changing the Port Mapping

Edit `docker-compose.yml`:

```yaml
ports:
  - "80:80"  # Use port 80 directly
  # or
  - "3000:80"  # Use a different port
```

## ⚙️ Environment Variable Configuration

You can add environment variables in `docker-compose.yml`:

```yaml
environment:
  - TZ=Asia/Shanghai
  # To adjust PHP upload and memory limits, modify the uploads.ini values in the Dockerfile and rebuild the image
```

## 🐛 Troubleshooting

### View Detailed Logs

```bash
docker-compose logs -f --tail=100 web
```

### Check Container Health Status

```bash
docker-compose ps
docker inspect siyuan-share-web | grep -A 10 Health
```

### Permission Issues

If you encounter file permission problems:

```bash
sudo chown -R $(id -u):$(id -g) php-site/storage php-site/uploads
chmod -R 775 php-site/storage php-site/uploads
```

### Port Already in Use

If port 8080 is occupied, edit `docker-compose.yml`:

```yaml
ports:
  - "8081:80"  # Switch to another port
```

## 📊 Performance Tuning

### Resource Limits

Add resource limits in `docker-compose.yml`:

```yaml
services:
  web:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 1G
        reservations:
          cpus: '0.5'
          memory: 256M
```

### Enable OPcache

In the current Dockerfile, change the existing extension install line to:

```dockerfile
RUN docker-php-ext-install -j"$(nproc)" gd pdo pdo_sqlite zip opcache
```

## 📝 Notes

1. **Data persistence**: The `storage/` and `uploads/` directories are mounted as volumes — data is retained even if the container is removed.
2. **Config file**: `config.php` is mounted read-only; after modifying it, restart the container.
3. **Security**: In production, always change the default password and configure HTTPS.
4. **Backup**: Regularly back up the SQLite database and uploaded files.

## 🔗 Related Links

- [Docker Official Documentation](https://docs.docker.com/)
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [Original Project README](README_zh_CN.md)
