#!/usr/bin/env bash
# Dockerizer for the LLM-driven trading bot (ai-trading-bot).
# In-place mode: project files are ALREADY at $TARGET_DIR/app (copied earlier).
# Generates Dockerfile/compose tailored to this app (PHP 8.3, queue workers,
# scheduler, Vite assets), builds/starts it, and checks host-MySQL connectivity.
# It does NOT import your DB, create MySQL users/grants, or change DNS.
# Review this script before running it on production systems.
set -Eeuo pipefail
umask 077
VERSION="2.1.0"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
log()  { printf "${BLUE}[%s]${NC} %s\n" "$(date '+%F %T')" "$*"; }
ok()   { printf "${GREEN}[ OK ]${NC} %s\n" "$*"; }
warn() { printf "${YELLOW}[WARN]${NC} %s\n" "$*" >&2; }
err()  { printf "${RED}[ERR ]${NC} %s\n" "$*" >&2; }
die()  { err "$*"; exit 1; }

retry_command() {
  local description="$1"; shift
  local attempt=1
  while true; do
    log "$description (attempt $attempt)"
    if "$@"; then return 0; fi
    warn "$description failed."
    read -r -p "Retry this step? [Y/n] " answer || true
    case "${answer,,}" in n|no) return 1 ;; *) attempt=$((attempt+1));; esac
  done
}
ask() {
  local __var="$1" prompt="$2" default="${3-}" value
  while true; do
    if [[ -n "$default" ]]; then read -r -p "$prompt [$default]: " value || true; value="${value:-$default}"
    else read -r -p "$prompt: " value || true; fi
    if [[ -n "$value" ]]; then printf -v "$__var" '%s' "$value"; return 0; fi
    warn "A value is required."
  done
}
ask_yes_no() {
  local __var="$1" prompt="$2" default="${3:-N}" answer
  while true; do
    read -r -p "$prompt [$default/y/n]: " answer || true
    answer="${answer:-$default}"
    case "${answer,,}" in y|yes) printf -v "$__var" '%s' yes; return 0;; n|no) printf -v "$__var" '%s' no; return 0;; *) warn 'Enter y or n.';; esac
  done
}
valid_name() { [[ "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9_-]{0,47}$ ]]; }
valid_port() { [[ "$1" =~ ^[0-9]+$ ]] && (( 10#$1 >= 1 && 10#$1 <= 65535 )); }

for bin in docker openssl python3 awk grep sed curl; do command -v "$bin" >/dev/null 2>&1 || die "Required command missing: $bin"; done
docker info >/dev/null 2>&1 || die 'Docker is not available to this user. Run as a user permitted to use Docker.'

cat <<'BANNER'
============================================================
 Trading-bot Dockerizer (in-place) — version 2.1.0
============================================================
Dockerizes the already-copied project, with queue workers and
the Laravel scheduler running inside the container via
supervisord. It does NOT import your DB, create MySQL
users/grants, configure reverse proxy, or change DNS.
BANNER

PROJECT=''; TARGET_DIR=''; HOST_PORT=''; CPU_LIMIT=''; MEMORY_LIMIT=''
DB_NAME=''; DB_USER=''; DB_PASSWORD=''; DB_HOST=''; DB_PORT=''; APP_URL=''
PHP_VERSION=''; NODE_MAJOR=''
PHP_FPM_PM=''; PHP_FPM_MAX_CHILDREN=''; PHP_FPM_START_SERVERS=''; PHP_FPM_MIN_SPARE=''; PHP_FPM_MAX_SPARE=''
USE_REDIS=''; REDIS_PASSWORD=''; RUN_MIGRATIONS=''; RUN_SEED=''

while true; do
  ask PROJECT 'Project/container name (letters, numbers, _ and -)' 'ai-trading-bot'
  if valid_name "$PROJECT"; then PROJECT="${PROJECT,,}"; break; fi
  warn 'Use 1-48 letters/numbers/underscores/hyphens; start with a letter or number.'
done
ask TARGET_DIR 'Project directory (must already contain app/ with artisan + .env)' "/opt/$PROJECT"
[[ -f "$TARGET_DIR/app/artisan" ]] || die "No artisan file at $TARGET_DIR/app. Copy the project there first."
[[ -f "$TARGET_DIR/app/.env" ]] || die "No .env at $TARGET_DIR/app/.env. Copy it (or .env.example) first."
cp -a "$TARGET_DIR/app/.env" "$TARGET_DIR/app/.env.pre-docker-backup"
ok "Backed up .env to $TARGET_DIR/app/.env.pre-docker-backup"

# Read current .env values so prompts default to what is already configured.
read_dotenv() { # $1=file $2=key
python3 - "$1" "$2" <<'PY'
import re, sys
path, want = sys.argv[1], sys.argv[2]
with open(path, 'r', encoding='utf-8', errors='replace') as f:
    for line in f:
        m = re.match(r'^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$', line)
        if m and m.group(1) == want:
            v = m.group(2)
            if len(v) >= 2 and v[0] == v[-1] and v[0] in ("'", '"'):
                v = v[1:-1]
            print(v, end='')
            break
PY
}
CUR_DB_HOST="$(read_dotenv "$TARGET_DIR/app/.env" DB_HOST)"
CUR_DB_PORT="$(read_dotenv "$TARGET_DIR/app/.env" DB_PORT)"
CUR_DB_NAME="$(read_dotenv "$TARGET_DIR/app/.env" DB_DATABASE)"
CUR_DB_USER="$(read_dotenv "$TARGET_DIR/app/.env" DB_USERNAME)"
CUR_APP_URL="$(read_dotenv "$TARGET_DIR/app/.env" APP_URL)"
CUR_DB_PASSWORD_SET='no'
[[ -n "$(read_dotenv "$TARGET_DIR/app/.env" DB_PASSWORD)" ]] && CUR_DB_PASSWORD_SET='yes'
log "Current .env DB: host=${CUR_DB_HOST:-?} port=${CUR_DB_PORT:-?} db=${CUR_DB_NAME:-?} user=${CUR_DB_USER:-?} password-set=${CUR_DB_PASSWORD_SET}"

ask HOST_PORT 'Localhost port for the web container' '8084'
while ! valid_port "$HOST_PORT"; do warn 'Invalid port.'; ask HOST_PORT 'Localhost port for the web container' '8084'; done
ask CPU_LIMIT 'Container CPU limit' '2'
ask MEMORY_LIMIT 'Container memory limit (Docker format, e.g. 2g)' '2g'

ask DB_HOST 'MySQL host from inside the container (host DB: host.docker.internal)' "${CUR_DB_HOST:-host.docker.internal}"
ask DB_PORT 'MySQL port' "${CUR_DB_PORT:-3306}"
while ! valid_port "$DB_PORT"; do warn 'Invalid port.'; ask DB_PORT 'MySQL port' '3306'; done
ask DB_NAME 'MySQL database name' "${CUR_DB_NAME:?missing DB_DATABASE in .env}"
ask DB_USER 'MySQL username' "${CUR_DB_USER:?missing DB_USERNAME in .env}"
read -r -s -p "MySQL password (leave blank to keep the .env password) [currently set: $CUR_DB_PASSWORD_SET]: " DB_PASSWORD || true
printf '\n'
if [[ -z "$DB_PASSWORD" ]]; then
  [[ "$CUR_DB_PASSWORD_SET" == yes ]] || die 'No password entered and none found in .env.'
  DB_PASSWORD="$(read_dotenv "$TARGET_DIR/app/.env" DB_PASSWORD)"
  ok 'Keeping the existing DB_PASSWORD from .env.'
fi

ask APP_URL 'Public APP_URL (update this to the real domain when known)' "${CUR_APP_URL:-http://127.0.0.1:$HOST_PORT}"
ask PHP_VERSION 'PHP version (this app requires ^8.2; local runtime is 8.3)' '8.3'
ask NODE_MAJOR 'Node.js major version for frontend builds (0 to skip Node install)' '22'
ask PHP_FPM_PM 'PHP-FPM process manager (dynamic or ondemand)' 'dynamic'
while [[ "$PHP_FPM_PM" != dynamic && "$PHP_FPM_PM" != ondemand ]]; do warn 'Choose dynamic or ondemand.'; ask PHP_FPM_PM 'PHP-FPM process manager' 'dynamic'; done
ask PHP_FPM_MAX_CHILDREN 'PHP-FPM pm.max_children' '20'
ask PHP_FPM_START_SERVERS 'PHP-FPM pm.start_servers' '4'
ask PHP_FPM_MIN_SPARE 'PHP-FPM pm.min_spare_servers' '2'
ask PHP_FPM_MAX_SPARE 'PHP-FPM pm.max_spare_servers' '6'

[[ "$PHP_FPM_MAX_CHILDREN" =~ ^[0-9]+$ && "$PHP_FPM_START_SERVERS" =~ ^[0-9]+$ && "$PHP_FPM_MIN_SPARE" =~ ^[0-9]+$ && "$PHP_FPM_MAX_SPARE" =~ ^[0-9]+$ ]] || die 'PHP-FPM values must be numeric.'
[[ "$PHP_FPM_MAX_CHILDREN" -gt 0 && "$PHP_FPM_START_SERVERS" -gt 0 && "$PHP_FPM_MIN_SPARE" -gt 0 && "$PHP_FPM_MAX_SPARE" -gt 0 ]] || die 'PHP-FPM values must be greater than zero.'

EXISTING_REDIS='no'
if grep -Eiq '^[[:space:]]*(CACHE_STORE|CACHE_DRIVER|SESSION_DRIVER|QUEUE_CONNECTION)[[:space:]]*=[[:space:]]*.*redis' "$TARGET_DIR/app/.env"; then EXISTING_REDIS='yes'; fi
if [[ "$EXISTING_REDIS" == yes ]]; then REDIS_DEFAULT='Y'; else REDIS_DEFAULT='N'; fi
ask_yes_no USE_REDIS "Enable a dedicated private Redis container? Existing Redis-backed Laravel drivers detected: $EXISTING_REDIS (this app uses database queues by default)" "$REDIS_DEFAULT"
if [[ "$EXISTING_REDIS" == yes && "$USE_REDIS" != yes ]]; then
  die 'This project currently configures a Redis-backed cache/session/queue driver. Enable Redis or change those Laravel drivers before migrating.'
fi
if [[ "$USE_REDIS" == yes ]]; then
  REDIS_PASSWORD="$(openssl rand -hex 32)"
fi

# Update dotenv values with Python so spaces, quotes, and special characters are escaped safely.
python3 - "$TARGET_DIR/app/.env" "$DB_NAME" "$DB_USER" "$DB_PASSWORD" "$DB_HOST" "$DB_PORT" "$APP_URL" "$USE_REDIS" "$REDIS_PASSWORD" <<'PY'
import re, sys
path, db_name, db_user, db_password, db_host, db_port, app_url, use_redis, redis_password = sys.argv[1:]
updates = {
    'DB_CONNECTION': 'mysql', 'DB_DATABASE': db_name, 'DB_USERNAME': db_user,
    'DB_PASSWORD': db_password, 'DB_HOST': db_host, 'DB_PORT': db_port,
    'APP_URL': app_url, 'APP_ENV': 'production', 'APP_DEBUG': 'false',
}
if use_redis == 'yes':
    updates.update({'REDIS_CLIENT':'phpredis','REDIS_HOST':'redis','REDIS_PASSWORD':redis_password,'REDIS_PORT':'6379'})
def quote(v):
    # Laravel dotenv supports double-quoted values; escape backslashes, quotes and newlines.
    return '"' + v.replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\r','\\r') + '"'
with open(path, 'r', encoding='utf-8', errors='replace') as f: lines = f.readlines()
seen = set(); out = []
for line in lines:
    m = re.match(r'^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=', line)
    if m and m.group(1) in updates:
        key=m.group(1)
        if key not in seen: out.append(f'{key}={quote(updates[key])}\n'); seen.add(key)
    else: out.append(line)
for key, value in updates.items():
    if key not in seen: out.append(f'{key}={quote(value)}\n')
with open(path, 'w', encoding='utf-8') as f: f.writelines(out)
PY
chmod 600 "$TARGET_DIR/app/.env"
ok 'Updated .env (APP_ENV=production, APP_DEBUG=false). Original saved as .env.pre-docker-backup.'

if [[ "$USE_REDIS" == yes ]]; then
  printf 'REDIS_PASSWORD=%s\n' "$REDIS_PASSWORD" > "$TARGET_DIR/redis.env"
  chmod 600 "$TARGET_DIR/redis.env"
fi

cat > "$TARGET_DIR/.dockerignore" <<'EOF'
.git
.env
app/.env*
redis.env
app/vendor
app/node_modules
app/storage/logs/*
app/storage/framework/cache/*
app/storage/framework/sessions/*
app/storage/framework/views/*
EOF

cat > "$TARGET_DIR/Dockerfile" <<EOF
FROM php:${PHP_VERSION}-fpm-bookworm
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \\
    nginx supervisor curl ca-certificates git unzip zip libzip-dev libicu-dev \\
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libonig-dev \\
    libxml2-dev libcurl4-openssl-dev libssl-dev \\
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \\
    && docker-php-ext-install -j"\$(nproc)" bcmath exif gd intl mbstring opcache pcntl pdo_mysql zip \\
    && pecl install redis \\
    && docker-php-ext-enable redis \\
    && rm -rf /tmp/pear /var/lib/apt/lists/*
EOF
if [[ "$NODE_MAJOR" != 0 ]]; then
cat >> "$TARGET_DIR/Dockerfile" <<EOF
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates gnupg \\
    && mkdir -p /etc/apt/keyrings \\
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \\
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \\
    && apt-get update && apt-get install -y --no-install-recommends nodejs \\
    && rm -rf /var/lib/apt/lists/*
EOF
fi
cat >> "$TARGET_DIR/Dockerfile" <<'EOF'
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY app/ /var/www/html/
RUN if [ -f composer.json ]; then composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader; fi
# Build frontend assets only if they were not built before copying (VITE_*
# values are baked at build time, so prefer building locally and copying
# public/build over rebuilding here).
RUN if [ -f package.json ] && [ ! -f public/build/manifest.json ]; then \
      (npm ci --no-audit --no-fund || npm install --no-audit --no-fund) && npm run build; \
    fi
RUN php artisan storage:link
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint \
    && mkdir -p /run/php /var/log/supervisor \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && true
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/app-entrypoint"]
EOF

mkdir -p "$TARGET_DIR/docker"
cat > "$TARGET_DIR/docker/nginx.conf" <<'EOF'
server {
    listen 80 default_server;
    server_name _;
    root /var/www/html/public;
    index index.php index.html;
    client_max_body_size 64m;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $document_root;
        fastcgi_read_timeout 120s;
        fastcgi_pass 127.0.0.1:9000;
    }
    location ~ /\.(?!well-known).* { deny all; }
}
EOF
# supervisord runs nginx + php-fpm AND this app's queue worker + scheduler.
# Without the worker/scheduler the bot cannot sync candles or trade.
cat > "$TARGET_DIR/docker/supervisord.conf" <<'EOF'
[unix_http_server]
file=/run/supervisor.sock
chmod=0700

[supervisorctl]
serverurl=unix:///run/supervisor.sock

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisord]
nodaemon=true
user=root
logfile=/dev/null
pidfile=/run/supervisord.pid

[program:php-fpm]
command=/usr/local/sbin/php-fpm --nodaemonize
autostart=true
autorestart=true
priority=10
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
autostart=true
autorestart=true
priority=20
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0

[program:queue]
command=php /var/www/html/artisan queue:work database --queue=market-data,intelligence,news,trading,trade-monitoring,learning,notifications,default --sleep=3 --tries=3 --timeout=300 --max-time=3600
autostart=true
autorestart=true
priority=30
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0
stopasgroup=true
killasgroup=true

[program:scheduler]
command=php /var/www/html/artisan schedule:work
autostart=true
autorestart=true
priority=40
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
stderr_logfile=/dev/fd/2
stderr_logfile_maxbytes=0
stopasgroup=true
killasgroup=true
EOF
cat > "$TARGET_DIR/docker/php-fpm-pool.conf" <<EOF
[www]
user = www-data
group = www-data
listen = 127.0.0.1:9000
pm = ${PHP_FPM_PM}
pm.max_children = ${PHP_FPM_MAX_CHILDREN}
pm.start_servers = ${PHP_FPM_START_SERVERS}
pm.min_spare_servers = ${PHP_FPM_MIN_SPARE}
pm.max_spare_servers = ${PHP_FPM_MAX_SPARE}
catch_workers_output = yes
clear_env = no
EOF
cat > "$TARGET_DIR/docker/entrypoint.sh" <<'EOF'
#!/bin/sh
set -eu
cd /var/www/html
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
# Keep migration safe: do not automatically run artisan migrate on production data.
if [ -f artisan ]; then
  php artisan config:clear >/dev/null 2>&1 || true
fi
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
EOF
chmod +x "$TARGET_DIR/docker/entrypoint.sh"

# Compose project name is explicit so the network can be discovered deterministically.
cat > "$TARGET_DIR/docker-compose.yml" <<EOF
name: ${PROJECT}
services:
  web:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: ${PROJECT}-web
    restart: unless-stopped
    ports:
      - "127.0.0.1:${HOST_PORT}:80"
    cpus: ${CPU_LIMIT}
    mem_limit: ${MEMORY_LIMIT}
    env_file:
      - ./app/.env
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - ./app/storage:/var/www/html/storage
    networks:
      - ${PROJECT}
EOF
if [[ "$USE_REDIS" == yes ]]; then
cat >> "$TARGET_DIR/docker-compose.yml" <<EOF
    depends_on:
      redis:
        condition: service_healthy

  redis:
    image: redis:7-alpine
    container_name: ${PROJECT}-redis
    restart: unless-stopped
    env_file:
      - ./redis.env
    command:
      - sh
      - -c
      - 'exec redis-server --appendonly yes --requirepass "\$\$REDIS_PASSWORD"'
    volumes:
      - redis_data:/data
    networks:
      - ${PROJECT}
    healthcheck:
      test:
        - CMD-SHELL
        - 'redis-cli -a "\$\$REDIS_PASSWORD" ping 2>/dev/null | grep -q PONG'
      interval: 10s
      timeout: 3s
      retries: 5
EOF
fi
cat >> "$TARGET_DIR/docker-compose.yml" <<EOF

networks:
  ${PROJECT}:
    driver: bridge
EOF
if [[ "$USE_REDIS" == yes ]]; then
cat >> "$TARGET_DIR/docker-compose.yml" <<EOF

volumes:
  redis_data:
    name: ${PROJECT}-redis-data
EOF
fi

cd "$TARGET_DIR"
while true; do
  if docker compose config -q; then ok 'Compose configuration is valid.'; break; fi
  warn 'Compose configuration is invalid.'
  read -r -p 'Open the file for editing, then retry validation? [Y/n] ' answer || true
  case "${answer,,}" in n|no) die 'Compose validation failed.';; esac
  "${EDITOR:-nano}" "$TARGET_DIR/docker-compose.yml"
done

retry_command 'Build Docker image' docker compose build --pull || die 'Docker image build aborted.'
retry_command 'Create containers and Docker network' docker compose create || die 'Container creation aborted.'

# Discover the actual Compose network subnet instead of assuming a fixed 172.x range.
if [[ "$DB_HOST" == host.docker.internal ]] && command -v ufw >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
  UFW_STATUS="$(sudo ufw status 2>/dev/null | head -n 1 || true)"
  if [[ "$UFW_STATUS" == 'Status: active' ]]; then
    NETWORK_NAME="$(docker network ls --filter "label=com.docker.compose.project=${PROJECT}" --filter "label=com.docker.compose.network=${PROJECT}" --format '{{.Name}}' | head -n 1)"
    if [[ -n "$NETWORK_NAME" ]]; then
      DOCKER_SUBNET="$(docker network inspect "$NETWORK_NAME" --format '{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null || true)"
      if [[ -n "$DOCKER_SUBNET" ]]; then
        UFW_RULES="$(sudo ufw status 2>/dev/null || true)"
        if grep -F "$DOCKER_SUBNET" <<< "$UFW_RULES" | grep -Fq "${DB_PORT}/tcp"; then
          ok "UFW already appears to contain a rule for $DOCKER_SUBNET to TCP/$DB_PORT."
        else
          warn "Host MySQL may be blocked by UFW. Compose subnet: $DOCKER_SUBNET; MySQL port: $DB_PORT."
          warn "This rule allows only containers from this project subnet to reach this host port."
          ask_yes_no ADD_UFW_RULE "Add UFW rule: allow from $DOCKER_SUBNET to TCP/$DB_PORT?" 'Y'
          if [[ "$ADD_UFW_RULE" == yes ]]; then
            sudo ufw allow from "$DOCKER_SUBNET" to any port "$DB_PORT" proto tcp || warn 'Could not add UFW rule. Add it manually if MySQL preflight fails.'
          else
            warn 'Skipped UFW rule. MySQL connectivity may fail if the firewall blocks this subnet.'
          fi
        fi
      else warn 'Could not determine Docker subnet; skipping automatic UFW rule.'; fi
    else warn 'Could not identify project Docker network; skipping automatic UFW rule.'; fi
  else log 'UFW is not active; skipping UFW rule automation.'; fi
else
  log 'Skipping UFW automation (DB_HOST is not host.docker.internal, UFW/sudo unavailable, or noninteractive sudo).'
fi

retry_command 'Start containers' docker compose up -d || die 'Container startup aborted.'

# Ensure containers are up before connection checks.
if ! docker compose ps; then warn 'Could not list Compose containers.'; fi
if [[ "$USE_REDIS" == yes ]]; then
  if docker compose exec -T redis sh -c 'redis-cli -a "$REDIS_PASSWORD" ping 2>/dev/null | grep -q PONG'; then ok 'Redis health check passed.'
  else warn 'Redis is not responding as expected. Check: docker compose logs redis'; fi
fi

log 'Checking MySQL TCP connectivity from inside the web container...'
if docker compose exec -T web php -r '$h=getenv("DB_HOST") ?: "host.docker.internal"; $p=(int)(getenv("DB_PORT") ?: 3306); $e=0; $s=""; $f=@fsockopen($h,$p,$e,$s,5); if (!$f) {fwrite(STDERR,"TCP connection failed to {$h}:{$p}: {$s} ({$e})\n"); exit(2);} fclose($f); echo "TCP connection succeeded to {$h}:{$p}\n";' ; then
  ok 'MySQL TCP port is reachable. This does not verify database existence, credentials, grants, or schema.'
else
  warn 'MySQL TCP preflight failed. Check DB_HOST/DB_PORT, MySQL bind/listen address, Docker subnet, UFW rules, and host MySQL grants.'
  docker compose logs --tail=80 web || true
  ask_yes_no CONTINUE_HTTP 'Continue to HTTP smoke test despite failed MySQL TCP preflight?' 'N'
  [[ "$CONTINUE_HTTP" == yes ]] || die 'Stopped after failed MySQL TCP preflight. Containers remain available for troubleshooting.'
fi

# Fresh remote database needs schema + seed data (idempotent seeders).
ask_yes_no RUN_MIGRATIONS 'Run database migrations inside the container now? (safe to skip if schema already exists)' 'Y'
if [[ "$RUN_MIGRATIONS" == yes ]]; then
  retry_command 'Run migrations' docker compose exec -T web php artisan migrate --force || warn 'Migrations failed; run them manually: docker compose exec web php artisan migrate --force'
  ask_yes_no RUN_SEED 'Seed default data (admin user, symbols, strategies, settings)? Skip if this DB already has data.' 'N'
  if [[ "$RUN_SEED" == yes ]]; then
    retry_command 'Run seeders' docker compose exec -T web php artisan db:seed --force || warn 'Seeding failed; run manually.'
  fi
fi

log 'Checking in-container workers (queue + scheduler)...'
if docker compose exec -T web supervisorctl status; then ok 'supervisorctl responded; queue and scheduler should show RUNNING above.'; else warn 'supervisorctl failed. Check: docker compose logs web'; fi

log "HTTP smoke test: http://127.0.0.1:${HOST_PORT}/ (this app redirects / to /admin, so 302 is a pass)"
HTTP_OK='no'
while true; do
  HTTP_CODE="$(curl -sS -o /tmp/laravel-migration-http-body.$$ -w '%{http_code}' --connect-timeout 5 --max-time 20 "http://127.0.0.1:${HOST_PORT}/" 2>/tmp/laravel-migration-curl-error.$$ || true)"
  if [[ "$HTTP_CODE" =~ ^(2[0-9][0-9]|3[0-9][0-9])$ ]]; then
    ok "HTTP smoke test returned $HTTP_CODE."; HTTP_OK='yes'; break
  fi
  if [[ "$HTTP_CODE" =~ ^5[0-9][0-9]$ ]]; then
    warn "HTTP returned $HTTP_CODE. This commonly means Laravel configuration, DB import/grants, permissions, or application code needs attention."
  elif [[ -z "$HTTP_CODE" || "$HTTP_CODE" == 000 ]]; then
    warn 'HTTP request failed or timed out.'
    cat "/tmp/laravel-migration-curl-error.$$" 2>/dev/null || true
  else warn "HTTP returned $HTTP_CODE."; fi
  echo '--- Recent web logs ---'
  docker compose logs --tail=100 web || true
  echo '--- Laravel log tail (if present) ---'
  docker compose exec -T web sh -lc 'test ! -f storage/logs/laravel.log || tail -n 80 storage/logs/laravel.log' || true
  warn 'A missing schema or invalid MySQL grants can prevent a successful HTTP response.'
  ask_yes_no RETRY_HTTP 'Retry HTTP smoke test?' 'N'
  if [[ "$RETRY_HTTP" != yes ]]; then
    ask_yes_no ACCEPT_HTTP_FAILURE 'Finish migration setup with HTTP still failing?' 'Y'
    [[ "$ACCEPT_HTTP_FAILURE" == yes ]] && break
  fi
done
rm -f "/tmp/laravel-migration-http-body.$$" "/tmp/laravel-migration-curl-error.$$" || true

cat <<EOF

============================================================
Migration setup finished
============================================================
Project directory : $TARGET_DIR
Web container     : ${PROJECT}-web
Local URL         : http://127.0.0.1:${HOST_PORT}/
Compose file      : $TARGET_DIR/docker-compose.yml
Redis enabled     : $USE_REDIS
HTTP smoke test   : $HTTP_OK

In-container processes (supervisord): nginx, php-fpm, queue worker,
scheduler. Verify with:
  cd '$TARGET_DIR'
  docker compose exec web supervisorctl status
  docker compose logs -f web

Useful commands:
  cd '$TARGET_DIR'
  docker compose ps
  docker compose logs -f web
  docker compose exec web php artisan about
  docker compose exec web php artisan config:clear

Before exposing the site, verify the database has been
migrated/seeded, DB user grants are correct, and the reverse-proxy
configuration points to 127.0.0.1:${HOST_PORT}.
Do not expose the app port publicly; it is bound to localhost only.
After first login, change the default admin password, set the
OpenRouter API key in Settings, and press Start in Bot control.
EOF
