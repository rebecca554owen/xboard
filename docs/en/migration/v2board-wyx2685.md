# V2board wyx2685 Migration Guide

This guide explains how to migrate from V2board wyx2685 to Xboard.

This migration command is idempotent. If you run it again on the same database, already applied table and column changes are skipped.

### 1. Database Changes Overview

- `v2_plan` table:
  - Removed `device_limit`

- `v2_server_hysteria` table:
  - Removed `version`
  - Removed `obfs`
  - Removed `obfs_password`

- `v2_server_trojan` table:
  - Removed `network`
  - Removed `network_settings`

- `v2_user` table:
  - Removed `device_limit`

### 2. Prerequisites

⚠️ Please complete the basic Xboard installation first (SQLite not supported):
- [Docker Compose Deployment](../installation/docker-compose.md)
- [aaPanel + Docker Deployment](../installation/aapanel-docker.md)
- [aaPanel Deployment](../installation/aapanel.md)

### 3. Migration Steps

#### Docker Environment

```bash
# 1. Stop services
docker compose down

# 2. Clear database
docker compose run -it --rm xboard php artisan db:wipe

# 3. Import old database (Important)
# Please manually import the V2board wyx2685 database

# 4. Execute migration
docker compose run -it --rm xboard php artisan migratefromv2b wyx2685
```

#### aaPanel Environment

```bash
# 1. Clear database
php artisan db:wipe

# 2. Import old database (Important)
# Please manually import the V2board wyx2685 database

# 3. Execute migration
php artisan migratefromv2b wyx2685
```

### 4. Configuration Migration

After completing the data migration, you need to migrate the configuration file:
- [Configuration Migration Guide](./config.md)
