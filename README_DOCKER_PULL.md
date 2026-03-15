# Siyuan Note Share Service - Docker Compose Deployment Guide (Pull Image)

This document uses `docker compose` to pull and run the image directly — no local image build required.

## Prerequisites

- Docker 20.10+
- Docker Compose v2 (command: `docker compose`)
- Network access to Docker Hub

Check your installation:

```bash
docker --version
docker compose version
```

## Quick Start

### 1. Prepare the Directory

```bash
# Replace with your own deployment directory if needed
mkdir -p ~/siyuan-share/php-site/storage ~/siyuan-share/php-site/uploads
cd ~/siyuan-share
```

### 2. Create `docker-compose.yml`

Save the following content as `docker-compose.yml`:

```yaml
services:
  web:
    image: b8l8u8e8/siyuan-share-web:latest
    container_name: siyuan-share-web
    ports:
      - "38080:80"
    volumes:
      - ./php-site/storage:/var/www/html/storage
      - ./php-site/uploads:/var/www/html/uploads
      # Uncomment the line below if you want to use a custom config
      # - ./php-site/config.php:/var/www/html/config.php:ro
    environment:
      TZ: Asia/Shanghai
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s
```

### 3. Pull the Image and Start

```bash
docker compose pull
docker compose up -d
```

### 4. View Status and Logs

```bash
docker compose ps
docker compose logs -f
```

### 5. Access the Service

Open in your browser: `http://<server-ip>:38080`

Default admin credentials:

- Username: `admin`
- Password: `123456`

Change your password immediately after the first login.

## Optional: Enable a Custom `config.php`

If you want to customize the site configuration, export `config.example.php` from the image first:

```bash
cd ~/siyuan-share
docker create --name sps-config-tmp b8l8u8e8/siyuan-share-web:latest
docker cp sps-config-tmp:/var/www/html/config.example.php ./php-site/config.php
docker rm sps-config-tmp
```

Edit `./php-site/config.php`, then uncomment the following line in `docker-compose.yml`:

```yaml
- ./php-site/config.php:/var/www/html/config.php:ro
```

Finally, restart the container:

```bash
docker compose up -d
```

## Common Operations

View service status:

```bash
docker compose ps
```

View recent logs:

```bash
docker compose logs --tail=200 web
```

Restart the service:

```bash
docker compose restart
```

Stop the service:

```bash
docker compose stop
```

Remove containers (mounted data is not deleted):

```bash
docker compose down
```

## Updating the Application

```bash
cd ~/siyuan-share
docker compose pull
docker compose up -d --remove-orphans
```

## Backup and Restore

Backup:

```bash
cd ~/siyuan-share
tar -czf backup-$(date +%Y%m%d).tar.gz \
  php-site/storage \
  php-site/uploads
```

If you have a custom config enabled, include it in the backup:

```bash
tar -czf backup-$(date +%Y%m%d).tar.gz \
  php-site/storage \
  php-site/uploads \
  php-site/config.php
```

Restore:

```bash
cd ~/siyuan-share
tar -xzf backup-20260114.tar.gz
docker compose up -d
```

## Troubleshooting

### 1) Image Pull Fails

- Check that the network can reach Docker Hub
- Verify the image name is correct: `b8l8u8e8/siyuan-share-web:latest`

### 2) Port Conflict

If port `38080` is already in use, change the port mapping in `docker-compose.yml`:

```yaml
ports:
  - "39180:80"
```

### 3) Directory Permission Issues

```bash
sudo chown -R $(id -u):$(id -g) ~/siyuan-share/php-site/storage ~/siyuan-share/php-site/uploads
chmod -R 775 ~/siyuan-share/php-site/storage ~/siyuan-share/php-site/uploads
```

### 4) Health Check Failing

```bash
docker inspect siyuan-share-web --format '{{json .State.Health}}'
```

## Notes

1. Always back up persistent data: `php-site/storage`, `php-site/uploads` (and the optional `php-site/config.php`).
2. In production, it is recommended to configure a reverse proxy and enable HTTPS.
3. Change the default admin password immediately after the first deployment.
