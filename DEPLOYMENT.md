# Quenyx vOPS HUB – Deployment

**PROPRIETARY SOFTWARE - Copyright (c) 2026 Quenyx CO. All rights reserved.**

This document describes how to deploy the Quenyx vOPS HUB monorepo (backend, frontend, gateway, optional agent gateway) for development and production.

**Canonical guide for a fresh production install:** use **Single-Node Deployment** (§1–11) on one Ubuntu host. Stack at a glance:

| Service | Role | Typical binding |
|---------|------|-----------------|
| Nginx | TLS, SPA (`frontend/dist`), `/api/` → gateway | `:443` (public) |
| Node gateway | Entitlements + proxy to Laravel | `127.0.0.1:4000` |
| PHP-FPM + Nginx | Laravel API (`backend/public`) | `127.0.0.1:8000` |
| MySQL | Application data | `127.0.0.1:3306` |
| Cron | `php artisan schedule:run` (QynSight native checks) | www-data crontab |
| systemd `quenyx-queue` | Port scans, RAG jobs | worker process |
| systemd `quenyx-gateway` | API gateway | see above |
| **Optional** QAG + Nginx TLS | Platform agents (`AGENT_REQUIRE_GATEWAY=true`) | `:9444` (public) |

QynSight monitoring is **native** (Laravel `observe:run-checks` / `observe:evaluate-alerts`). There is **no** Nagios container or `docker-compose` stack in this repo. See [docs/OBSERVE_RUNBOOK.md](docs/OBSERVE_RUNBOOK.md).

Packaged docs: [docs/quenyx-v1/10_DEPLOYMENT_GUIDE.md](docs/quenyx-v1/10_DEPLOYMENT_GUIDE.md) and [docs/quenyx-v1/43_DEPLOYMENT_CHECKLIST_v1.0.md](docs/quenyx-v1/43_DEPLOYMENT_CHECKLIST_v1.0.md).

## Prerequisites

| Component   | Version / Notes |
|------------|------------------|
| PHP        | **8.2 or 8.3** recommended (8.1+ minimum); PHP-FPM + CLI with extensions below |
| PHP extensions | `mbstring`, `pdo_mysql`, `openssl`, `curl`, `fileinfo`, `bcmath`, `xml`, `zip`, `intl` (and `gd` if you serve images from Laravel) |
| Composer   | 2.x              |
| Node.js    | 18 LTS or **20 LTS** (recommended for builds) |
| npm        | 9+ (ships with Node 20; use `npm ci` in CI/deploy) |
| MySQL      | **8.0+** (MariaDB 10.6+ may work but is not the tested target) |
| Nginx      | Reverse proxy, static frontend, `/api/` → gateway, optional TLS on `:9444` (QAG) |
| Git        | Clone and deploy updates |
| Optional   | **Go 1.21+** — on-demand agent binary builds; **certbot** — Let’s Encrypt TLS |

Examples in §6 systemd/Nginx use **`php8.2-fpm`** and **`/run/php/php8.2-fpm.sock`**. Set **`PHPVER`** to match what you installed (`8.2`, `8.3`, `8.5`, …) and substitute package/service/socket names everywhere (`php${PHPVER}-fpm`, `/run/php/php${PHPVER}-fpm.sock`).

**Install and configure base packages on the server before** [Single-Node Deployment](#single-node-deployment-development--staging). Supported families:

| OS | Tested / documented |
|----|---------------------|
| **Ubuntu** | **22.04 LTS (jammy)**, **24.04 LTS (noble)**, **25.04+ / resolute** — see PHP matrix below (PPA **not** on resolute) |
| **Debian** 12 (Bookworm) | `packages.sury.org/php` (not Launchpad PPA) |
| **RHEL** 8 / 9, **Rocky Linux**, **AlmaLinux**, **CentOS Stream** 8 / 9 | `dnf` + Remi PHP module below |

> **Windows/macOS** are fine for **development** (`artisan serve`, `npm run dev`). **Production GA** in this guide assumes **Linux + Nginx + PHP-FPM** as documented in §6.

---

## Host prerequisites — install by OS

Run these on a **fresh** single-node host (as root or with `sudo`). Replace mirror URLs if your policy requires internal mirrors.

### Common after any OS install

1. Set hostname and timezone (example):

   ```bash
   sudo timedatectl set-timezone UTC
   ```

2. Create an app tree (adjust path):

   ```bash
   sudo mkdir -p /var/www/quenyx
   sudo chown "$USER":"$USER" /var/www/quenyx
   ```

3. After MySQL is installed, **edit the password in the SQL file**, then apply databases:

   ```bash
   cd /var/www/quenyx/quenyx-saas   # your clone path
   nano scripts/mysql-quenyx-setup.sql
   # Replace both CHANGE_ME strings with one strong password (match backend/.env DB_PASSWORD later)

   mysql -u root -p < scripts/mysql-quenyx-setup.sql
   ```

   Verify:

   ```bash
   mysql -u quenyx -p -e "SHOW DATABASES LIKE 'quenyx%';"   # production: expect `quenyx` only
   ```

---

### Ubuntu (22.04 LTS and newer)

**Check your release first** (drives which PHP repository works):

```bash
source /etc/os-release
echo "Ubuntu $VERSION_ID ($VERSION_CODENAME)"
```

| Codename | Ubuntu | PHP — use this method |
|----------|--------|------------------------|
| `jammy` | 22.04 LTS | **A** Launchpad PPA `ppa:ondrej/php`, **or B** [packages.sury.org](https://packages.sury.org/php/), **or C** distro packages if ≥ 8.2 |
| `noble` | 24.04 LTS | **A** PPA, **or B** Sury, **or C** native `php8.3-*` / `php8.4-*` from Ubuntu |
| `plucky`, `resolute`, … | 25.04+ | **Do not use** `ppa:ondrej/php` (404 / no Release file on new codenames). Use **B** Sury **or C** native `php8.5-*` (or whatever `apt` offers ≥ 8.1) |

Quenyx backend requires **PHP 8.1+** (`composer.json`). **8.2 / 8.3** are common in production; **8.5 from Ubuntu archives is fine** on Resolute if you use method **C**.

**1. Base tools**

```bash
sudo apt update
sudo apt install -y git curl unzip ca-certificates gnupg lsb-release
```

**2. PHP** — pick **one** method below (on **resolute**, use **B** or **C** only).

#### If you already added the PPA and `apt update` fails (404 on `resolute`)

Remove the broken PPA, then pick method **B** or **C** below:

```bash
sudo add-apt-repository --remove ppa:ondrej/php -y 2>/dev/null || true
sudo rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list
sudo apt update
```

#### Method A — Launchpad PPA (Jammy & Noble only)

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
export PHPVER=8.2
sudo apt install -y \
  php${PHPVER}-fpm php${PHPVER}-cli \
  php${PHPVER}-mysql php${PHPVER}-mbstring php${PHPVER}-xml php${PHPVER}-curl \
  php${PHPVER}-zip php${PHPVER}-bcmath php${PHPVER}-intl php${PHPVER}-gd php${PHPVER}-opcache
sudo systemctl enable --now php${PHPVER}-fpm
php${PHPVER} -v
```

#### Method B — Sury PHP repository (all Ubuntu versions, including Resolute)

Official path when the PPA message says to use [packages.sury.org/php](https://packages.sury.org/php/) (required on **resolute** and recommended on Debian):

```bash
sudo apt update
sudo apt install -y lsb-release ca-certificates curl
sudo curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
sudo tee /etc/apt/sources.list.d/php.list <<EOF
deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main
EOF
sudo apt update
export PHPVER=8.3
sudo apt install -y \
  php${PHPVER}-fpm php${PHPVER}-cli \
  php${PHPVER}-mysql php${PHPVER}-mbstring php${PHPVER}-xml php${PHPVER}-curl \
  php${PHPVER}-zip php${PHPVER}-bcmath php${PHPVER}-intl php${PHPVER}-gd php${PHPVER}-opcache
sudo systemctl enable --now php${PHPVER}-fpm
php${PHPVER} -v
```

Change `PHPVER` to `8.2`, `8.3`, or `8.4` as offered by `apt-cache search php | grep fpm`.

#### Method C — Ubuntu archive PHP only (simplest on Resolute)

When `apt` already ships a suitable version (your host suggested **`php8.5-cli`**):

```bash
sudo apt update
export PHPVER=8.5
sudo apt install -y \
  php${PHPVER}-fpm php${PHPVER}-cli \
  php${PHPVER}-mysql php${PHPVER}-mbstring php${PHPVER}-xml php${PHPVER}-curl \
  php${PHPVER}-zip php${PHPVER}-bcmath php${PHPVER}-intl php${PHPVER}-gd php${PHPVER}-opcache
sudo systemctl enable --now php${PHPVER}-fpm
php${PHPVER} -v
```

List available FPM packages if unsure:

```bash
apt-cache search --names-only '^php[0-9\.]+-fpm$' | sort
```

Tune FPM (optional): `/etc/php/${PHPVER}/fpm/pool.d/www.conf` → `sudo systemctl reload php${PHPVER}-fpm`.

Set **`OBSERVE_PHP_CLI=/usr/bin/php${PHPVER}`** in `backend/.env` when using plugin checks (CLI must match FPM major version).

**3. Composer**

```bash
curl -sS https://getcomposer.org/installer | php${PHPVER}
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

**4. Node.js 20 LTS + npm**

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v && npm -v
```

**5. MySQL 8.0**

```bash
sudo apt install -y mysql-server
sudo systemctl enable --now mysql
# Harden defaults (set root password, remove test DB, etc.):
sudo mysql_secure_installation
```

Create app user/database via `scripts/mysql-quenyx-setup.sql` — **replace `CHANGE_ME` in the file** (both `CREATE USER` lines) before running; use the same password in `backend/.env` as `DB_PASSWORD`.

**6. Nginx**

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

**7. Optional — Go (agent builds); Certbot package (run Certbot only after §5 Nginx — see §5b)**

```bash
sudo apt install -y golang-go certbot python3-certbot-nginx
go version
# Do NOT run certbot until port 80 is open and Nginx serves your domain (DEPLOYMENT.md §5b).
```

**8. Firewall (UFW) — example for public web + QAG**

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'    # 80/443
sudo ufw allow 9444/tcp      # only if Agent Gateway is public on this host
sudo ufw enable
```

**9. Verify toolchain**

```bash
php${PHPVER} -m | grep -E 'pdo_mysql|mbstring|bcmath'
composer --version
node -v && npm -v
mysql --version
nginx -v
systemctl is-active php${PHPVER}-fpm nginx mysql
```

---

### Debian 12 (Bookworm)

Use **Sury** (method **B** above) with `lsb_release -sc` → `bookworm`. Do **not** use the Ubuntu Launchpad PPA on Debian.

Then continue with the same Composer, NodeSource, MySQL, and Nginx steps as in the Ubuntu section.

---

### RHEL 8 / 9, Rocky Linux, AlmaLinux, CentOS Stream 8 / 9

Package names differ from Debian; PHP comes from **Remi**. Adjust `remi-release-*.rpm` for your major version (`-8.rpm` vs `-9.rpm`).

**1. Base tools**

```bash
sudo dnf install -y git curl unzip tar ca-certificates
```

**2. PHP 8.2 + PHP-FPM (Remi)**

```bash
sudo dnf install -y epel-release
# RHEL 9 / Rocky 9 / Alma 9 example:
sudo dnf install -y https://rpms.remirepo.net/enterprise/remi-release-9.rpm
# RHEL 8 / Rocky 8: use remi-release-8.rpm instead

sudo dnf module reset php -y
sudo dnf module enable php:remi-8.2 -y
sudo dnf install -y \
  php php-fpm php-cli php-mysqlnd php-mbstring php-xml php-curl \
  php-zip php-bcmath php-intl php-gd php-opcache
sudo systemctl enable --now php-fpm
php -v
```

Default FPM socket is often **`/run/php-fpm/www.sock`** (not `/run/php/php8.2-fpm.sock`). In Nginx and docs, set:

```nginx
fastcgi_pass unix:/run/php-fpm/www.sock;
```

**3. Composer**

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

**4. Node.js 20 LTS**

```bash
curl -fsSL https://rpm.nodesource.com/setup_20.x | sudo bash -
sudo dnf install -y nodejs
node -v && npm -v
```

**5. MySQL 8**

```bash
sudo dnf install -y mysql-server
sudo systemctl enable --now mysqld
# First start may log temporary root password in /var/log/mysqld.log — change it, then run:
sudo mysql_secure_installation
```

Apply `scripts/mysql-quenyx-setup.sql` after root access is configured (**replace `CHANGE_ME` in the file first**).

**6. Nginx**

```bash
sudo dnf install -y nginx
sudo systemctl enable --now nginx
```

**7. SELinux (if enforcing)**

Allow Nginx to connect to PHP-FPM and upstream proxy:

```bash
sudo setsebool -P httpd_can_network_connect 1
# If you use non-default paths under /var/www:
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/quenyx(/.*)?" 2>/dev/null || true
sudo restorecon -Rv /var/www/quenyx 2>/dev/null || true
```

(`policycoreutils-python-utils` provides `semanage` if missing.)

**8. App file ownership on RHEL**

Debian examples use **`www-data`**. On RHEL, either:

- Run queue/gateway units as **`nginx`** and `chown -R nginx:nginx backend/storage backend/bootstrap/cache`, **or**
- Create a matching user: `sudo groupadd -r www-data; sudo useradd -r -g www-data -s /sbin/nologin www-data` and use the same `User=` as in §6.

**9. Firewall (firewalld)**

```bash
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --permanent --add-port=9444/tcp   # if QAG exposed
sudo firewall-cmd --reload
```

**10. Optional — Go, Certbot**

```bash
sudo dnf install -y golang
# Certbot: use snap or epel certbot package per your distro policy
```

---

### TLS (Let’s Encrypt) — see §5b

Do **not** run Certbot until **§5 Nginx** is deployed and **port 80 is reachable from the public internet** (DNS, host firewall, cloud security group). Full checklist: **[§5b TLS (Let’s Encrypt)](#5b-tls-lets-encrypt--after-nginx-on-port-80)**.

---

### Cron user for Laravel scheduler

Ubuntu/Debian: run scheduler as **`www-data`** (matches PHP-FPM pool user):

```bash
sudo crontab -u www-data -e
```

RHEL (if using `nginx` user for PHP-FPM): use `sudo crontab -u nginx -e` and the same `User=` in `quenyx-queue.service`.

---

### Build host note

You may install **Node + npm only** on a CI/build machine to produce `frontend/dist` and copy artifacts to the app server. The **app server** still needs PHP, Composer, MySQL, Nginx, PHP-FPM, Node (for gateway `npm ci` / `dist/`), and optionally Go on the same host unless you pre-build gateway `dist/` as well.

---

## Single-Node Deployment (Development / Staging)

Single server runs: Laravel backend, Node gateway, Nginx serving frontend static build and proxying `/api/` to the gateway.

### 1. Clone and enter repo

```bash
git clone <repository-url>
cd quenyx-saas
```

### 1b. MySQL (first-time or Quenyx rename)

**Database naming**

| Environment | MySQL database | Bootstrap script |
|-------------|----------------|------------------|
| **Production** | **`quenyx`** | `scripts/mysql-quenyx-setup.sql` or `mysql-quenyx-setup-production.sql` |
| **Local dev / staging** | `quenyx_dev` | `scripts/mysql-quenyx-setup-dev.sql` |
| **PHPUnit / CI only** | `quenyx_test` | same dev script (do **not** create on production) |

**Production fresh install**

**1. Edit the bootstrap password** in `scripts/mysql-quenyx-setup.sql`: replace **both** `'CHANGE_ME'` literals in the `CREATE USER` lines.

**2. Apply** (creates database **`quenyx`** only):

```bash
mysql -u root -p < scripts/mysql-quenyx-setup.sql
```

**3. Match Laravel** (`backend/.env`):

```env
APP_ENV=production
DB_DATABASE=quenyx
DB_USERNAME=quenyx
DB_PASSWORD=<same password as in the SQL file>
```

**4. Test login:**

```bash
mysql -u quenyx -p quenyx -e "SELECT 1;"
```

**Already created `quenyx_dev` / `quenyx_test` on production by mistake**

If those databases are **empty** (no migrations yet):

```bash
mysql -u root -p -e "DROP DATABASE IF EXISTS quenyx_dev; DROP DATABASE IF EXISTS quenyx_test;"
mysql -u root -p < scripts/mysql-quenyx-setup.sql
```

If **`quenyx_dev` already has data** (migrations/seeds ran):

```bash
mysql -u root -p < scripts/mysql-quenyx-rename-to-production.sql
mysqldump -u root -p --single-transaction quenyx_dev | mysql -u root -p quenyx
# Update backend/.env: DB_DATABASE=quenyx
cd backend && php artisan config:clear && php artisan config:cache
# After verification:
mysql -u root -p -e "DROP DATABASE quenyx_dev; DROP DATABASE IF EXISTS quenyx_test;"
```

**Local development** uses `mysql-quenyx-setup-dev.sql` and `DB_DATABASE=quenyx_dev` — see `backend/TESTING.md`.

Existing servers with pre-rebrand **database names** (not `_dev` suffix): `docs/QUENYX_DEPLOYMENT_AND_CHANGES.md` §4.2 and `scripts/migrate-mysql-to-quenyx-databases.sh`.

### 2. Backend

> **Before any `php artisan` command:** run **`composer install`** once so `backend/vendor/` exists.
> If you see `Failed to open stream: vendor/autoload.php`, Composer dependencies were not installed (see **Troubleshooting** below).

**Prerequisites on the server:** `composer` in PATH (`composer --version`), PHP CLI matching FPM (e.g. `php8.5` or `php`), and extensions from [Host prerequisites](#host-prerequisites--install-by-os).

```bash
cd /var/www/quenyx/Quenyx-saas/backend   # adjust to your clone path

# 1) Dependencies (creates vendor/ — required)
composer install --no-dev --optimize-autoloader

# 2) Environment (first deploy only)
cp .env.example .env
# Edit .env: APP_ENV=production, APP_DEBUG=false, DB_DATABASE=quenyx, DB_USERNAME=quenyx,
# DB_PASSWORD=..., APP_URL=https://prod.quenyx.com, GATEWAY_INTERNAL_SECRET=..., etc.

# 3) Application bootstrap
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force

# 4) Production caches (after .env is final)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan quenyx:config-check --strict
```

Verify `vendor/autoload.php` exists: `test -f vendor/autoload.php && echo OK`.

**Note:** `view:cache` requires `backend/resources/views/` to exist (a placeholder `welcome.blade.php` is included). If deploy fails with *directory does not exist*, pull latest backend or create the directory before caching.

**QynSight / observe routing:** Do **not** set `OBSERVE_ENGINE_URL` on the gateway. All `/api/*` traffic (including observe) must go to `BACKEND_BASE_URL` only. Legacy split-engine routing caused empty hosts and 60s hangs.

Set `SEED_ADMIN_PASSWORD` in backend `.env` before running seeds.
Seed admin login will be: `admin@quenyx.test` / `<SEED_ADMIN_PASSWORD>`.

### 3. Frontend (reproducible build)

```bash
cd ../frontend
npm ci
npm run build
```

Static output: `frontend/dist/`. Nginx will serve this as the document root.

`npm run build` also copies bundled HTML documentation from `build/docs-html/` into `frontend/dist/docs/` (API Reference, guides, release notes). Help Center links (`/docs`, `/docs/api`, `/docs/release-notes`) depend on this folder — ensure `build/docs-html` exists on the build host (run `scripts/docs/build-pdfs.ps1` if missing).

### 4. Gateway

```bash
cd ../gateway
npm ci
npm run build
```

**Important:** After any gateway code change, run `npm run build` and restart the gateway service so `dist/` is updated.

**Environment (systemd or .env):**

```bash
GATEWAY_PORT=4000
BACKEND_BASE_URL=http://127.0.0.1:8000
ENTITLEMENTS_CACHE_TTL_MS=30000
```

**Backend `.env` – Agent install instructions:**

Set `GATEWAY_BASE_URL` in `backend/.env` to your **public** gateway URL (e.g. `https://quenyx.example.com`). This is used for agent download and enrollment commands. If unset, it falls back to `APP_URL` or `http://127.0.0.1:4000`.

```bash
GATEWAY_BASE_URL=https://your-public-domain.com
```

After changing `.env`, run `php artisan config:clear` (or `php artisan config:cache` in production).

**Quenyx AI (production GA):**

```env
AI_ENABLED=          # unset = auto-enable when OPENAI_API_KEY is set; false = disabled by admin
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
```

Run `php artisan quenyx:config-check --strict` before go-live.

**After deploy, verify the AI fix is live** (in browser DevTools → Network → `workspace/summary`):

| Field | Old (broken) | Fixed |
|---|---|---|
| `runtime_resolver` | missing | `"v2"` |
| `runtime_mode` | missing / wrong | `"live"` when OpenAI configured |
| Mock chat text | `ai.feature_flags.enabled` | real OpenAI reply or clear 503 error |

If `runtime_resolver` is missing, production is still on pre-`f743bc3` code — pull, rebuild frontend, and restart PHP-FPM.

**Production `.env` checklist:**

```env
# Remove AI_ENABLED=false if present — it blocks live AI even when OpenAI is configured.
# Either omit AI_ENABLED entirely (auto-enables when OPENAI_API_KEY is set) or:
AI_ENABLED=true
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
```

After any `.env` change: `php artisan config:clear && php artisan config:cache` and restart PHP-FPM.

**Changing public domain (e.g. `dev.quenyx.com` → `cloud.quenyx.com`):**

Laravel caches config — updating `.env` alone is not enough until you re-cache. Also update every layer that references the old hostname:

| Layer | What to set |
|-------|-------------|
| **backend `.env`** | `APP_URL=https://cloud.quenyx.com` and `GATEWAY_BASE_URL=https://cloud.quenyx.com` (same public origin when nginx serves SPA + `/api`) |
| **CORS** | `CORS_ALLOWED_ORIGINS=https://cloud.quenyx.com` (not the old dev hostname) |
| **Session (HTTPS prod)** | `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax` |
| **Sanctum (if cookie SPA)** | `SANCTUM_STATEFUL_DOMAINS=cloud.quenyx.com` |
| **nginx** | `server_name cloud.quenyx.com;` and TLS cert for the new name |
| **Frontend build** | Leave `VITE_API_BASE_URL` **empty** for same-origin `/api` **or** set it to `https://cloud.quenyx.com` — then **`npm run build`** again (old builds embed the previous URL) |
| **Gateway** | No public URL needed in gateway `.env`; keep `BACKEND_BASE_URL=http://127.0.0.1:8000`. Do **not** set `OBSERVE_ENGINE_URL`. |

```bash
cd backend
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan quenyx:config-check

cd ../frontend
# ensure .env.production or build env has VITE_API_BASE_URL= (empty) or https://cloud.quenyx.com
npm run build
sudo systemctl reload nginx
sudo systemctl restart quenyx-gateway php8.2-fpm
```

Users who logged in on `dev.quenyx.com` should clear site data or log in again on `cloud.quenyx.com` (tokens in `localStorage` are origin-scoped).

**Agent binaries (for Install Agent download):**

The route `GET /api/agents/download/{platform}` serves the agent binary. When a binary is missing, the server can build it on demand (requires Go). Configure in `backend/.env`:

- **AGENT_GO_BINARY** – Path to the Go binary. PHP-FPM often has a minimal PATH, so set this to the full path (e.g. `AGENT_GO_BINARY=/usr/bin/go`). If unset, `go` is used and may not be found when the web server runs.
- **AGENT_SOURCE_PATH** – Directory containing the agent Go module (`go.mod`). Default is the `agent/` directory next to the backend (e.g. repo root `agent/`). Set if your deploy layout differs.
- **AGENT_BUILD_ON_DEMAND** – Set to `false` to disable on-demand build and only serve pre-built binaries from `storage/app/agents/`.

Ensure **backend/storage/app/agents** exists and is writable by the web server user (e.g. `www-data`), so on-demand build can write binaries, Go cache dirs (`.gocache`, `.gomodcache`), and the download endpoint can serve them. Example: `sudo mkdir -p backend/storage/app/agents && sudo chown www-data:www-data backend/storage/app/agents && sudo chmod 775 backend/storage/app/agents`.

Built or pre-placed files in `backend/storage/app/agents/`: `linux-amd64`, `linux-arm64`, `windows-amd64`, `windows-arm64`, `darwin-amd64`, `darwin-arm64`. See `agent/README.md` for manual build commands. If the server cannot build or find a binary, the endpoint returns JSON with a `message` explaining the reason (e.g. "Go binary not found").

**Quenyx Agent Gateway (QAG) — required when `AGENT_REQUIRE_GATEWAY=true` (production default in `.env.example`):**

Agents enroll and send telemetry to QAG, not to Laravel directly. On the same host (or a dedicated ingress):

```bash
cd agent-gateway
npm ci
npm run build
```

Set `backend/.env` to your public agent endpoint (example):

```env
AGENT_GATEWAY_PROTOCOL=https
AGENT_GATEWAY_HOST=cloud.quenyx.com
AGENT_GATEWAY_PORT=9444
AGENT_REQUIRE_GATEWAY=true
GATEWAY_BASE_URL=https://cloud.quenyx.com
```

Copy `agent-gateway/.env.example` → `agent-gateway/.env` with `BACKEND_BASE_URL=http://127.0.0.1:8000`. Terminate TLS on Nginx (`listen 9444 ssl`) and proxy to the Node process (default `QAG_PORT=9444`). Full examples: [agent-gateway/README.md](agent-gateway/README.md), [docs/AGENT_ARCHITECTURE.md](docs/AGENT_ARCHITECTURE.md).

Example systemd unit `/etc/systemd/system/quenyx-agent-gateway.service`:

```ini
[Unit]
Description=Quenyx Agent Gateway
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/quenyx/quenyx-saas/agent-gateway
Environment="BACKEND_BASE_URL=http://127.0.0.1:8000"
ExecStart=/usr/bin/node dist/server.js
Restart=always

[Install]
WantedBy=multi-user.target
```

Enable with `systemctl enable --now quenyx-agent-gateway`.

**Gateway readiness (native QynSight):** `curl http://127.0.0.1:4000/ready` — observe engine is `"native"`; legacy `/internal/engines/nagios*` returns `410 Gone`. See [gateway/README.md](gateway/README.md).

### 5. Nginx (single node)

Replace **`prod.quenyx.com`** and paths with your domain and install directory (example: `/var/www/quenyx/Quenyx-saas`).

**5a. HTTP site (required before Certbot)**

Create `/etc/nginx/sites-available/quenyx`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name prod.quenyx.com;

    root /var/www/quenyx/Quenyx-saas/frontend/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:4000;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Authorization $http_authorization;
        proxy_cache_bypass $http_upgrade;

        proxy_connect_timeout 60s;
        proxy_send_timeout 180s;
        proxy_read_timeout 180s;
    }
}
```

Enable and test:

```bash
sudo ln -sf /etc/nginx/sites-available/quenyx /etc/nginx/sites-enabled/quenyx
sudo rm -f /etc/nginx/sites-enabled/default   # if it conflicts on port 80
sudo nginx -t
sudo systemctl reload nginx
```

Confirm locally:

```bash
curl -sI -H 'Host: prod.quenyx.com' http://127.0.0.1/ | head
```

### 5b. TLS (Let’s Encrypt) — after Nginx on port 80

Certbot failed with **`Timeout during connect (likely firewall problem)`** when **Let’s Encrypt cannot reach your server on port 80**. Fix prerequisites **in this order**:

| Step | Check |
|------|--------|
| **DNS** | `prod.quenyx.com` **A record** points to this server’s **public** IP (`dig +short prod.quenyx.com` matches `curl -s ifconfig.me`) |
| **Cloud security group** | On **Alibaba Cloud / AWS / Azure**, allow **inbound TCP 80 and 443** to this instance (mirrors alone do not open the firewall) |
| **Host firewall** | `sudo ufw status` — allow `Nginx Full` or `80/tcp` and `443/tcp` **before** Certbot |
| **Nginx** | Site enabled (§5a), `server_name` matches the domain, `listen 80` |
| **Services** | Gateway on `4000`, PHP-FPM backend on `8000` (Certbot only needs HTTP for the challenge, but the site should respond) |

```bash
# Example UFW (if enabled)
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

**Verify from the internet** (another machine or an online HTTP checker):

```bash
curl -sI http://prod.quenyx.com/
```

You must get an HTTP response (200/301/404), not a timeout.

**Then** request the certificate (install certbot if needed: `sudo apt install -y certbot python3-certbot-nginx`):

```bash
sudo certbot --nginx -d prod.quenyx.com \
  --non-interactive --agree-tos -m admin@your-company.com
```

Certbot edits the Nginx server block to add `listen 443 ssl` and redirects. Reload is automatic.

**Staging test** (optional, avoids rate limits while debugging):

```bash
sudo certbot --nginx -d prod.quenyx.com --staging --dry-run
```

**After TLS:** set `APP_URL=https://prod.quenyx.com`, `GATEWAY_BASE_URL=https://prod.quenyx.com`, CORS/Sanctum domains, rebuild frontend if needed, `php artisan config:cache` (see domain change table in §4).

Renewal: Certbot installs a systemd timer or cron; check with `sudo systemctl list-timers | grep certbot`.

**Agent gateway TLS (optional):** separate vhost or `listen 9444 ssl` with `certbot certonly --nginx -d agents.prod.quenyx.com` if you use a dedicated hostname.

---

**Backend (production: PHP-FPM, NOT `php artisan serve`)**

> ⚠️ **GA requirement:** `php artisan serve` is a single-threaded development
> server and must **not** be used in production. Serve the Laravel backend with
> **PHP-FPM** behind a dedicated Nginx server block. The Node gateway proxies to
> this backend (`BACKEND_BASE_URL=http://127.0.0.1:8000`).

1. Install PHP-FPM (e.g. `php8.2-fpm`) and confirm the pool socket
   (`/run/php/php8.2-fpm.sock`). PHP-FPM is managed by its own systemd unit
   (`php8.2-fpm.service`) — no custom backend unit is needed.

2. Add a backend Nginx server block listening on `127.0.0.1:8000` with the
   document root at `backend/public`:

   ```nginx
   server {
       listen 127.0.0.1:8000;
       server_name _;
       root /var/www/quenyx/quenyx-saas/backend/public;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.2-fpm.sock;
           # RHEL/Rocky/Alma (Remi php-fpm): often unix:/run/php-fpm/www.sock
           fastcgi_read_timeout 180s;
       }

       location ~ /\.(?!well-known).* { deny all; }
       client_max_body_size 25m;
   }
   ```

3. Validate config and warm caches after deploy:

   ```bash
   cd backend
   php artisan quenyx:config-check   # fails fast on production misconfig
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   sudo systemctl reload php8.2-fpm nginx
   ```

> For containerized deployments, run `php-fpm` as the container command instead
> of `artisan serve`, with Nginx (sidecar or ingress) in front.

**Gateway**

`/etc/systemd/system/quenyx-gateway.service`:

```ini
[Unit]
Description=Quenyx API Gateway
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/quenyx/quenyx-saas/gateway
Environment="GATEWAY_PORT=4000"
Environment="BACKEND_BASE_URL=http://127.0.0.1:8000"
Environment="ENTITLEMENTS_CACHE_TTL_MS=30000"
ExecStart=/usr/bin/node dist/server.js
Restart=always

[Install]
WantedBy=multi-user.target
```

### 7. Laravel scheduler (cron) – required for QynSight

The scheduler runs **`observe:run-checks` every two minutes**, **`observe:evaluate-alerts` every minute**, and **`observe:run-port-scans` every five minutes** (see `backend/app/Console/Kernel.php`). **Without cron + `schedule:run`, Real-time Monitoring and Infrastructure Map will show stale or "never" data.**

Add to the **www-data** user crontab (or the user that runs the backend):

```bash
sudo crontab -u www-data -e
```

Add this line (adjust the path if your backend lives elsewhere):

```
* * * * * cd /var/www/quenyx/quenyx-saas/backend && php artisan schedule:run >> /var/www/quenyx/quenyx-saas/backend/storage/logs/scheduler.log 2>&1
```

**Verify:**
- Wait 1–2 minutes, then check `backend/storage/logs/scheduler.log` – it should contain output from `observe:run-checks` (e.g. "Ran X native check(s).").
- If the file stays empty, confirm: (1) crontab is installed for the correct user, (2) PHP path is correct (`which php`), (3) `storage/logs/` is writable by the cron user.

**After adding or changing `config/queue.php`**, run:
```bash
cd backend && php artisan config:clear && php artisan config:cache
```

### 8. Queue worker (for port scans and other background jobs)

Port scans (especially full 1–65535) run as background jobs. **Without a queue worker, scans will not complete.**

1. **Create jobs table** (if not exists):
   ```bash
   cd backend && php artisan migrate --force
   ```

2. **Set queue driver** in `.env`:
   ```
   QUEUE_CONNECTION=database
   ```

3. **Add queue worker systemd service** `/etc/systemd/system/quenyx-queue.service`:
   ```ini
   [Unit]
   Description=Quenyx Queue Worker
   After=network.target

   [Service]
   Type=simple
   User=www-data
   WorkingDirectory=/var/www/quenyx/quenyx-saas/backend
   ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

4. **Enable and start**:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable quenyx-queue
   sudo systemctl start quenyx-queue
   ```

**Verify:** Trigger a port scan from the UI; check `storage/logs/laravel.log` or the Port Scan tab for results.

### 9. Enable and start

```bash
sudo systemctl daemon-reload
# Backend is served by PHP-FPM (php8.2-fpm), not a custom quenyx-backend unit.
sudo systemctl enable php8.2-fpm quenyx-gateway quenyx-queue
sudo systemctl start php8.2-fpm quenyx-gateway quenyx-queue
sudo systemctl reload nginx
```

### 10. Health checks

- Gateway: `curl http://127.0.0.1:4000/health` → `{"status":"ok","service":"gateway"}`
- Backend **liveness**: `curl http://127.0.0.1:8000/api/health` → `200` lightweight liveness response.
- Backend **readiness**: `curl http://127.0.0.1:8000/api/health/ready` → `200` when DB/cache are reachable, `503` when not. Use this for load-balancer / orchestrator readiness probes (do **not** route traffic until it returns `200`).

### 11. Backups, restore verification & disaster recovery

Automated, verified logical backups are provided under `scripts/`:

```bash
# Create a compressed, checksummed, self-verifying backup (safe for cron).
scripts/backup-db.sh /var/backups/quenyx

# Prove a backup is restorable WITHOUT touching the live DB (temp DB, auto-dropped).
scripts/restore-db.sh /var/backups/quenyx/quenyx-<db>-<timestamp>.sql.gz

# Real restore into a target database (interactive confirmation required).
scripts/restore-db.sh <backup_file.sql.gz> --target quenyx
```

Schedule nightly backups + a weekly restore-verification via cron:

```cron
# Nightly backup at 02:30 (retains BACKUP_RETENTION_DAYS, default 14).
30 2 * * *  /var/www/quenyx/quenyx-saas/scripts/backup-db.sh /var/backups/quenyx >> /var/log/quenyx-backup.log 2>&1
# Weekly restore verification (Sundays 03:30) against the most recent backup.
30 3 * * 0  f=$(ls -t /var/backups/quenyx/quenyx-*.sql.gz | head -n1); /var/www/quenyx/quenyx-saas/scripts/restore-db.sh "$f" >> /var/log/quenyx-restore-verify.log 2>&1
```

> **DR note:** Store backups off-host (object storage / different volume). The
> backup script writes a `.sha256` sidecar; `restore-db.sh` verifies gzip
> integrity and checksum before any restore, so a corrupt backup fails loudly
> instead of silently restoring bad data.

---

## Multi-Node Deployment (Production)

- **Load balancer**: Nginx (SSL termination, route `/` to frontend, `/api/` to gateway pool).
- **Frontend**: Static files from `frontend/dist/` (or CDN).
- **Gateway**: 2+ stateless nodes; in-memory entitlement cache only.
- **Backend**: 2+ Laravel nodes; shared MySQL (and optional Redis for sessions/cache).

### Load balancer Nginx (example)

```nginx
upstream gateway {
    least_conn;
    server gateway1.internal:4000;
    server gateway2.internal:4000;
}

server {
    listen 443 ssl http2;
    server_name quenyx.net;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://frontend-cdn-or-origin;
    }

    location /api/ {
        proxy_pass http://gateway;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Authorization $http_authorization;
    }
}
```

### Gateway nodes

On each node:

```bash
cd gateway
npm ci
npm run build
```

Set `BACKEND_BASE_URL` to the backend pool or load balancer URL. After code changes: rebuild then restart the gateway service.

### Backend nodes

On each node:

```bash
cd backend
composer install --no-dev --optimize-autoloader
# .env: shared DB, same APP_KEY on all nodes
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

### Health and monitoring

- Gateway: `GET /health` → `{"status":"ok","service":"gateway"}`; `GET /ready` for native observe engine status.
- Backend: `GET /api/health`.
- Monitor ports 4000 (gateway) and 8000 (backend); set alerts on 502/503 and DB connectivity.

---

## Production readiness

- **Backend:** Use `APP_ENV=production`, `APP_DEBUG=false`, and a strong `APP_KEY`. Run `php artisan config:cache` and `route:cache` after deployment. Ensure `APP_URL` matches the public URL (HTTPS). Do not expose `.env` or storage.
- **Frontend:** Build with `npm run build`; set `VITE_API_BASE_URL` to your API base (e.g. `https://your-domain.com` so that relative `/api/` requests go to the same origin). Do not ship source maps in production if you want to hide source.
- **Gateway:** No secrets in logs; use env vars for `BACKEND_BASE_URL`. Restart after any code or config change.
- **Security:** HTTPS only in production; restrict CORS to your frontend origin; change or remove seeded default credentials before go-live.
- **Errors:** In production, Laravel should not display stack traces to users (controlled by `APP_DEBUG`). Frontend can show a generic “Something went wrong” and log details client-side or to an error reporting service.

---

## Production environment checklist

Before going live:

| Item | Action |
|------|--------|
| **Backend .env** | `APP_ENV=production`, `APP_DEBUG=false`, strong `APP_KEY`, correct `APP_URL` (HTTPS), `DB_DATABASE=quenyx`, `DB_USERNAME=quenyx`, `DB_PASSWORD` |
| **Config validation** | Run `php artisan quenyx:config-check` (use `--strict` in CI). Validates AI_ENABLED, AI_PROVIDER, OPENAI_API_KEY, and blocks silent mock in production |
| **CORS** | Set `CORS_ALLOWED_ORIGINS` to the exact frontend origin(s) (no `*`); set `SANCTUM_STATEFUL_DOMAINS`. `CORS_SUPPORTS_CREDENTIALS=true` requires an explicit origin |
| **Token expiry** | `SANCTUM_TOKEN_EXPIRATION_MINUTES` set (default 7 days). Schedule runs `sanctum:prune-expired` daily |
| **Security headers** | `SECURITY_HEADERS_ENABLED=true`; HSTS enabled (served over HTTPS). CSP/Referrer/Permissions policies applied by `SecurityHeaders` middleware |
| **Login protection** | `AUTH_LOGIN_MAX_ATTEMPTS` / `AUTH_REGISTER_MAX_ATTEMPTS` tuned; login is rate-limited per email + IP |
| **Sessions** | `SESSION_SECURE_COOKIE=true` (HTTPS only) |
| **Frontend build** | Leave `VITE_API_BASE_URL` **empty** for same-origin `/api` behind Nginx, **or** set to your public origin — then **`npm run build`** |
| **Seeded credentials** | Set `SEED_ADMIN_PASSWORD` before seeding; rotate after first login if required |
| **Gateway internal secret** | Set strong `GATEWAY_INTERNAL_SECRET` in both backend and gateway env; deployment should fail if missing |
| **Workspaces** | Seeder creates only **Production Env** and **Staging Env**; adjust in `ProjectSeeder` if needed |
| **HTTPS** | Use Nginx (or load balancer) with SSL; redirect HTTP → HTTPS |
| **Gateway** | `BACKEND_BASE_URL` must point to backend (e.g. `http://127.0.0.1:8000` or internal LB URL) |
| **No dev deps** | Backend: `composer install --no-dev`. Frontend/gateway: use built assets; no dev servers in production |
| **Laravel scheduler** | Add crontab for `php artisan schedule:run` (see §7). Without it, QynSight checks never run and "Last poll: never" appears |
| **Agent gateway** | If `AGENT_REQUIRE_GATEWAY=true`, deploy QAG + TLS on `:9444` and set `AGENT_GATEWAY_*` in backend `.env` (see §4) |
| **Pre-flight** | `php artisan quenyx:config-check --strict` before traffic |

---

## Real testing before go-live

Run these flows on a staging or production build:

1. **Login** — Log in with production-ready credentials.
2. **Workspace** — Switch workspace (Workspace dropdown); confirm Dashboard and Observe data change (or show empty state for the other workspace).
3. **Empty state** — In a workspace with no hosts, open Real-time Monitoring and Dashboard; confirm “No hosts in this workspace” and “Add hosts” CTA.
4. **Add host** — In Monitored Targets, add a host (name + address); save.
5. **Observe data** — Open Real-time Monitoring (and Dashboard); confirm host appears (or service totals update once services are added).
6. **Infrastructure Map** — Open Map; confirm hosts appear; test export (JSON/PNG).
7. **Integrations** — Open Integrations; configure webhook (optional); confirm settings save.
8. **Getting started** — Open **Getting started** from sidebar; confirm steps and links work.

If all pass, the platform is ready for production use.

---

## Reproducible installs

- **Frontend:** Commit `package-lock.json`; use `npm ci` in CI and deployment.
- **Backend:** Use `composer install --no-dev --optimize-autoloader` with a locked `composer.lock`.
- **Gateway:** Use `npm ci` and `npm run build`; always restart after rebuilding.

---

## Updating a deployment

1. **Backend:** `git pull`, `composer install --no-dev`, `php artisan migrate --force`, `php artisan config:cache` (and route/view cache as needed), restart app.
2. **Frontend:** `git pull`, `npm ci`, `npm run build`; refresh static hosting (or CDN).
3. **Gateway:** `git pull`, `npm ci`, `npm run build`, restart gateway service.

---

## Troubleshooting (deploy)

### `vendor/autoload.php`: No such file or directory

**Cause:** `composer install` was not run in `backend/`, or failed partway (no `vendor/` directory).

**Fix:**

```bash
cd /var/www/quenyx/Quenyx-saas/backend
composer --version    # must work; if not, install Composer (Host prerequisites § Ubuntu step 3)
composer install --no-dev --optimize-autoloader
test -f vendor/autoload.php && php artisan --version
```

Then rerun `php artisan config:clear`, `migrate`, etc. **`vendor/` is not in git** — every new clone or server needs `composer install`.

### `composer install` fails (missing ext-*, memory)

Install PHP extensions from Host prerequisites (`php*-mysql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`). Retry:

```bash
COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --optimize-autoloader
```

### `php artisan` uses wrong PHP binary

If multiple PHP versions are installed, call the same binary as FPM, e.g. `php8.5 artisan config:clear` or `update-alternatives --set php /usr/bin/php8.5`.

### Migration fails: index name too long (MySQL 1059)

**Cause:** MySQL limits identifier names to **64 characters**. Fixed in `2026_07_05_010000_create_knowledge_collaboration_tables` (short index names) + repair migration `2026_07_21_000001_fix_collaboration_index_names`.

**Fix:** Pull latest code, then:

```bash
cd backend
php artisan migrate --force
php artisan migrate:status   # all rows should show [Ran]
```

If migrate stops again on Sprint 24 tables left half-created:

```bash
mysql -u root -p quenyx -e "
  DROP TABLE IF EXISTS collaboration_participants, collaboration_comments;
"
php artisan migrate --force
```

The repair migration adds missing indexes if tables exist without them.

### `view:cache` — View path not found

**Cause:** `backend/resources/views/` missing on the server (incomplete checkout).

**Fix:**

```bash
cd /var/www/quenyx/Quenyx-saas
git pull
test -d backend/resources/views || mkdir -p backend/resources/views
test -f backend/resources/views/welcome.blade.php || echo '<!-- placeholder -->' > backend/resources/views/welcome.blade.php
cd backend && php artisan view:cache
```

Or skip `view:cache` if you do not serve Blade views (API-only); it is optional for the SPA gateway path.

### `php artisan quenyx:config-check --strict` fails on production

Set **`backend/.env`** before caching config (then `php artisan config:clear && php artisan config:cache`):

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://prod.quenyx.com

GATEWAY_BASE_URL=https://prod.quenyx.com
GATEWAY_INTERNAL_SECRET=<generate: openssl rand -hex 32>
# Same value in gateway/.env

CORS_ALLOWED_ORIGINS=https://prod.quenyx.com
CORS_SUPPORTS_CREDENTIALS=false
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=prod.quenyx.com

# Required for --strict unless you intentionally run without live AI:
AI_PROVIDER=openai
OPENAI_API_KEY=sk-...
# Or omit OPENAI_API_KEY only if you accept strict failure until AI is configured
```

Match **`GATEWAY_INTERNAL_SECRET`** on the gateway service environment. Re-run `php artisan quenyx:config-check --strict`.

---

## License

**PROPRIETARY SOFTWARE - Copyright (c) 2026 Quenyx CO. All rights reserved.**
