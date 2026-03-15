# Siyuan Note Share Service - Docker Deployment Guide (Pure Docker CLI)

This document deploys the service using only `docker` commands, without any orchestration tools.

## 📋 Prerequisites

- Docker 20.10+
- Network access to Docker Hub

Check your installation:

```bash
docker --version
```

## 🚀 Quick Start

### 1. Prepare the Working Directory

```bash
# Replace `~` below with your actual deployment directory; keep it consistent in all subsequent commands
mkdir -p ~/siyuan-share/php-site/storage ~/siyuan-share/php-site/uploads
cd ~/siyuan-share
```

> ⚠️ Unless otherwise noted, all subsequent commands should be run from the deployment directory (e.g. `~/siyuan-share`).
> If you chose a different path, `cd` to that directory first before running any command.

### 2. Configuration File (Optional)

`config.php` is not required.
You can skip it and start the container with the default configuration.

Only create and mount `config.php` if you need to customize parameters (e.g. app name, upload directory, chunk parameters, etc.).

### 3. Pull the Image

```bash
docker pull b8l8u8e8/siyuan-share-web:latest
```

### 4. Start the Container

```bash
# Make sure you are in the deployment directory first (e.g. cd ~/siyuan-share)
docker run -d \
  --name siyuan-share-web \
  --restart unless-stopped \
  -p 38080:80 \
  -e TZ=Asia/Shanghai \
  -v "$(pwd)/php-site/storage:/var/www/html/storage" \
  -v "$(pwd)/php-site/uploads:/var/www/html/uploads" \
  --health-cmd="curl -f http://localhost/ || exit 1" \
  --health-interval=30s \
  --health-timeout=10s \
  --health-retries=3 \
  --health-start-period=40s \
  b8l8u8e8/siyuan-share-web:latest
```

### 5. View Logs

```bash
docker logs -f siyuan-share-web
```

### 6. Access the Application

Open in your browser: `http://<server-ip>:38080`

Default admin credentials:

- Username: `admin`
- Password: `123456`

Change your password immediately after the first login.

## 📁 Data Directories

- `php-site/storage`: Database and other persistent data
- `php-site/uploads`: Uploaded files
- `php-site/config.php`: Optional site configuration (mount only when customization is needed)

## ⚙️ Optional: Enable a Custom `config.php`

If you need a custom configuration, it is recommended to copy `config.example.php` directly from the image to avoid typos:

```bash
# Make sure you are in the deployment directory first (e.g. cd ~/siyuan-share)
docker create --name sps-config-tmp b8l8u8e8/siyuan-share-web:latest
docker cp sps-config-tmp:/var/www/html/config.example.php ./php-site/config.php
docker rm sps-config-tmp
```

Then recreate the container with the config file mounted:

```bash
# Make sure you are in the deployment directory first (e.g. cd ~/siyuan-share)
docker rm -f siyuan-share-web
docker run -d \
  --name siyuan-share-web \
  --restart unless-stopped \
  -p 38080:80 \
  -e TZ=Asia/Shanghai \
  -v "$(pwd)/php-site/storage:/var/www/html/storage" \
  -v "$(pwd)/php-site/uploads:/var/www/html/uploads" \
  -v "$(pwd)/php-site/config.php:/var/www/html/config.php:ro" \
  --health-cmd="curl -f http://localhost/ || exit 1" \
  --health-interval=30s \
  --health-timeout=10s \
  --health-retries=3 \
  --health-start-period=40s \
  b8l8u8e8/siyuan-share-web:latest
```

## 🔧 Common Operations

View container status:

```bash
docker ps -a --filter "name=siyuan-share-web"
```

View recent logs:

```bash
docker logs --tail=200 siyuan-share-web
```

Restart the container:

```bash
docker restart siyuan-share-web
```

Stop the container:

```bash
docker stop siyuan-share-web
```

Remove the container (mounted data on the host is not deleted):

```bash
docker rm -f siyuan-share-web
```

## 🔄 Updating the Application

```bash
# Make sure you are in the deployment directory first (e.g. cd ~/siyuan-share)
docker pull b8l8u8e8/siyuan-share-web:latest
docker rm -f siyuan-share-web
docker run -d \
  --name siyuan-share-web \
  --restart unless-stopped \
  -p 38080:80 \
  -e TZ=Asia/Shanghai \
  -v "$(pwd)/php-site/storage:/var/www/html/storage" \
  -v "$(pwd)/php-site/uploads:/var/www/html/uploads" \
  --health-cmd="curl -f http://localhost/ || exit 1" \
  --health-interval=30s \
  --health-timeout=10s \
  --health-retries=3 \
  --health-start-period=40s \
  b8l8u8e8/siyuan-share-web:latest
```

## 💾 Backup and Restore

Backup:

```bash
cd ~/siyuan-share
tar -czf backup-$(date +%Y%m%d).tar.gz \
  php-site/storage \
  php-site/uploads
```

If you are using a custom `config.php`, include it in the backup as well:

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
docker restart siyuan-share-web
```

## 🩺 Troubleshooting

### 1) Image Pull Fails

- Check that the image name is correct
- Check that the server can reach Docker Hub
- Test with: `docker pull b8l8u8e8/siyuan-share-web:latest`

### 2) Port Conflict

If port `38080` is already in use, change `-p 38080:80` in the run command to another port, e.g. `-p 39180:80`.

### 3) Directory Permission Issues

```bash
sudo chown -R $(id -u):$(id -g) ~/siyuan-share/php-site/storage ~/siyuan-share/php-site/uploads
chmod -R 775 ~/siyuan-share/php-site/storage ~/siyuan-share/php-site/uploads
```

### 4) Health Check Failing

View the health status:

```bash
docker inspect siyuan-share-web --format '{{json .State.Health}}'
```

## ❗ Notes

1. Always back up `php-site/storage` and `php-site/uploads` (and `php-site/config.php` if you use it).
2. Make sure data is saved to mounted directories before removing the container.
3. In production, it is recommended to add a reverse proxy and configure HTTPS.
