# Docker Usage (Nginx)

This project runs fully in Docker with Nginx as the web server.

## Development (Recommended)

```bash
# Copy docker env
cp .env.docker.example .env

# Optional: avoid permission issues on macOS/Linux
export UID=$(id -u)
export GID=$(id -g)

# Build & start
docker compose build
docker compose up -d

# Install dependencies and setup
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate:fresh --seed
docker compose run --rm app php artisan storage:link

docker compose up -d node
```

Quick start (scripted):

```bash
./scripts/docker/dev-up.sh
```

Reset database (destructive, dev only):

```bash
./scripts/docker/dev-reset.sh
```

Open:
- http://localhost:8080 (or the `WEB_PORT` you set)
- http://localhost:8025 (Mailpit)

## Production (Enterprise-Grade)

Builds immutable images (no bind mounts), with Nginx serving optimized assets.

```bash
# Use production env
cp .env.prod.example .env

# Build images
docker compose -f docker-compose.prod.yml build

# Start stack
docker compose -f docker-compose.prod.yml up -d

# One-time setup
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml run --rm app php artisan storage:link
```

Quick start (scripted):

```bash
./scripts/docker/prod-up.sh
```

## Backups (MySQL)

Automatic daily backups via a dedicated container.

```bash
# Production + backups
docker compose -f docker-compose.prod.yml up -d --build
```

Config in `.env`:

```env
BACKUP_SCHEDULE=0 2 * * *   # 02:00 every day
BACKUP_RETENTION_DAYS=7
BACKUP_DB_USER=root
BACKUP_DB_PASSWORD=root
```

Backups are stored in a Docker volume (`backup-data`).  
For offsite storage, sync the backups volume to S3/NAS.

## Monitoring (Prometheus + Grafana)

```bash
# Start monitoring stack alongside your current compose file
docker compose -f docker-compose.prod.yml -f docker-compose.monitor.yml up -d
```

Open:
- http://localhost:9090 (Prometheus)
- http://localhost:3000 (Grafana)
- http://localhost:9093 (Alertmanager)

Grafana login:
- User: `GRAFANA_ADMIN_USER` (default: admin)
- Password: `GRAFANA_ADMIN_PASSWORD` (default: admin / change-me)

Dashboard:
- Auto-provisioned: "CreativeTrees - Overview"

Alerting:
- Prometheus rules enabled (`docker/monitoring/prometheus.rules.yml`)

## Tools (DB/Redis UI)

```bash
# Start tools alongside your current compose file
docker compose -f docker-compose.prod.yml -f docker-compose.monitor.yml -f docker-compose.tools.yml up -d
```

Open:
- http://localhost:8082 (Adminer - MySQL UI)
- http://localhost:8083 (phpMyAdmin)
- http://localhost:8001 (RedisInsight)

Adminer login (from `.env`):
- Server: `mysql`
- Username: `DB_USERNAME`
- Password: `DB_PASSWORD`
- Database: `DB_DATABASE`

phpMyAdmin login:
- Server: `mysql`
- Username: `root`
- Password: `DB_ROOT_PASSWORD` (default: root)

## Scaling

```bash
# Example: scale app + queue workers
docker compose -f docker-compose.prod.yml up -d --scale app=2 --scale queue=2
```

Notes:
- Sessions/queue/cache already use Redis, so horizontal scaling is safe.
- Uploaded files are shared via Docker volumes. For multi-host scaling, move storage to S3 or a shared filesystem.

## Commands

```bash
# Stop
docker compose down

# Logs
docker compose logs -f

# Artisan
docker compose run --rm app php artisan <command>
```

## Port Conflicts

Change `WEB_PORT` in `.env` to avoid conflicts:

```env
WEB_PORT=8080
```
