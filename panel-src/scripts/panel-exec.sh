#!/usr/bin/env bash
# ==============================================================================
# panel-exec.sh - THE sole privilege boundary between the panel (running as
# the unprivileged 'panel' user) and root-level system operations.
#
# Invoked ONLY via: sudo /opt/server-panel/scripts/panel-exec.sh <subcommand> [args...]
# The sudoers rule (installed by modules/panel.sh) restricts the 'panel' user
# to executing exactly this script as root, nothing else.
#
# Design rules (do not weaken):
#   - Fixed whitelist of subcommands (case statement below). Unknown
#     subcommand => exit 2, nothing executed.
#   - Every argument is validated against a strict regex BEFORE use.
#   - No eval. No unquoted variable expansion in executed commands.
#   - File paths are always re-derived from validated identifiers and
#     confined under a fixed base directory (realpath prefix check) -
#     never taken as a raw path from the caller.
#   - Bulk content (nginx config, PM2 ecosystem file) is read from STDIN,
#     never from argv, to avoid argv-length/quoting foot-guns.
#   - Every invocation is appended to the audit log with timestamp, caller
#     uid and subcommand - never with secret payloads (env values, tokens).
# ==============================================================================
set -euo pipefail
umask 027

AUDIT_LOG="/opt/server-panel/storage/logs/panel-exec-audit.log"
NGINX_AVAILABLE="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
WWW_BASE="/var/www"
NODEAPPS_BASE="/home/nodeapps/apps"
NODEAPPS_HOME="/home/nodeapps"
BACKUP_BASE="/opt/server-panel/storage/backups"
ACME_WEBROOT="/var/www/_letsencrypt"

mkdir -p "$(dirname "$AUDIT_LOG")"
audit() {
    echo "$(date -Iseconds) uid=$(id -u) caller=${SUDO_USER:-unknown} subcommand=$1 status=$2" >> "$AUDIT_LOG"
}

fail() {
    echo "ERROR: $1" >&2
    audit "${SUBCOMMAND:-unknown}" "error:$1"
    exit 1
}

# ---------------------------------------------------------------------------
# Validators - exit non-zero (via fail) on mismatch
# ---------------------------------------------------------------------------
require_match() {
    local value="$1" pattern="$2" label="$3"
    [[ "$value" =~ $pattern ]] || fail "Argumen tidak valid untuk ${label}: '${value}'"
}

require_path_within() {
    # require_path_within <path> <base-dir>
    local path="$1" base="$2"
    local resolved
    resolved=$(realpath -m -- "$path")
    local resolved_base
    resolved_base=$(realpath -m -- "$base")
    case "$resolved" in
        "$resolved_base"/*) ;;
        *) fail "Path di luar batas yang diizinkan: $path" ;;
    esac
    printf '%s' "$resolved"
}

RE_SITENAME='^[a-zA-Z0-9._-]{1,200}$'
RE_APPNAME='^[a-zA-Z0-9_-]{1,64}$'
RE_DOMAIN='^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$'
RE_EMAIL='^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'
RE_DBNAME='^[a-zA-Z0-9_]{1,64}$'
RE_LINES='^[0-9]{1,4}$'
RE_PORT='^[0-9]{1,5}$'

# ---------------------------------------------------------------------------
# Nginx operations
# ---------------------------------------------------------------------------
op_nginx_test() {
    nginx -t
}

op_nginx_reload() {
    nginx -t
    systemctl reload nginx
}

op_nginx_write_config() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    local target="${NGINX_AVAILABLE}/${site}.conf"
    require_path_within "$target" "$NGINX_AVAILABLE" >/dev/null

    local tmp
    tmp=$(mktemp)
    cat > "$tmp"

    if [[ ! -s "$tmp" ]]; then
        rm -f "$tmp"
        fail "Konten konfigurasi kosong"
    fi

    local previous_backup=""
    if [[ -f "$target" ]]; then
        previous_backup=$(mktemp)
        cp -a "$target" "$previous_backup"
    fi

    mv "$tmp" "$target"
    chown root:root "$target"
    chmod 644 "$target"

    if ! nginx -t 2>/tmp/nginx-test-err.$$; then
        if [[ -n "$previous_backup" ]]; then
            mv "$previous_backup" "$target"
        else
            rm -f "$target"
        fi
        local err
        err=$(cat /tmp/nginx-test-err.$$ 2>/dev/null || true)
        rm -f "/tmp/nginx-test-err.$$"
        fail "nginx -t gagal, konfigurasi dibatalkan: ${err}"
    fi
    rm -f "/tmp/nginx-test-err.$$" "$previous_backup" 2>/dev/null || true
    echo "OK: konfigurasi ${site} ditulis dan valid"
}

op_nginx_enable() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    local src="${NGINX_AVAILABLE}/${site}.conf"
    local dst="${NGINX_ENABLED}/${site}.conf"
    [[ -f "$src" ]] || fail "Konfigurasi ${site} tidak ditemukan"
    ln -sf "$src" "$dst"
    if ! nginx -t; then
        rm -f "$dst"
        fail "nginx -t gagal setelah enable, dibatalkan"
    fi
    systemctl reload nginx
    echo "OK: ${site} enabled"
}

op_nginx_disable() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    local dst="${NGINX_ENABLED}/${site}.conf"
    rm -f "$dst"
    nginx -t
    systemctl reload nginx
    echo "OK: ${site} disabled"
}

op_nginx_delete() {
    local site="$1"
    require_match "$site" "$RE_SITENAME" "sitename"
    rm -f "${NGINX_ENABLED}/${site}.conf" "${NGINX_AVAILABLE}/${site}.conf"
    nginx -t
    systemctl reload nginx
    echo "OK: ${site} deleted"
}

# ---------------------------------------------------------------------------
# PM2 / Node.js operations - always executed as the 'nodeapps' user
# ---------------------------------------------------------------------------
as_nodeapps() {
    runuser -u nodeapps -- bash -lc "export NVM_DIR='${NODEAPPS_HOME}/.nvm'; [ -s \"\$NVM_DIR/nvm.sh\" ] && . \"\$NVM_DIR/nvm.sh\"; $*"
}

op_pm2_deploy() {
    local app="$1"
    require_match "$app" "$RE_APPNAME" "appname"
    local app_dir="${NODEAPPS_BASE}/${app}"
    require_path_within "$app_dir" "$NODEAPPS_BASE" >/dev/null

    mkdir -p "$app_dir"
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    [[ -s "$tmp" ]] || { rm -f "$tmp"; fail "Ecosystem config kosong"; }

    mv "$tmp" "${app_dir}/ecosystem.config.js"
    chown -R nodeapps:nodeapps "$app_dir"
    chmod 750 "$app_dir"
    chmod 640 "${app_dir}/ecosystem.config.js"

    as_nodeapps "pm2 start '${app_dir}/ecosystem.config.js' --update-env"
    as_nodeapps "pm2 save"
    echo "OK: ${app} deployed via PM2"
}

op_pm2_start() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 start '${app}'"
    as_nodeapps "pm2 save"
}

op_pm2_stop() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 stop '${app}'"
    as_nodeapps "pm2 save"
}

op_pm2_restart() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 restart '${app}'"
}

op_pm2_reload() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 reload '${app}'"
}

op_pm2_delete() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 delete '${app}'" || true
    as_nodeapps "pm2 save"
}

op_pm2_jlist() {
    as_nodeapps "pm2 jlist"
}

op_pm2_describe() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 describe '${app}'"
}

op_pm2_logs() {
    local app="$1" lines="${2:-100}"
    require_match "$app" "$RE_APPNAME" "appname"
    require_match "$lines" "$RE_LINES" "lines"
    [[ "$lines" -le 1000 ]] || lines=1000
    as_nodeapps "pm2 logs '${app}' --lines ${lines} --nostream"
}

op_pm2_flush() {
    local app="$1"; require_match "$app" "$RE_APPNAME" "appname"
    as_nodeapps "pm2 flush '${app}'"
}

op_pm2_save() {
    as_nodeapps "pm2 save"
}

# ---------------------------------------------------------------------------
# Certbot / SSL
# ---------------------------------------------------------------------------
op_certbot_issue() {
    local domain="$1" email="$2"
    require_match "$domain" "$RE_DOMAIN" "domain"
    require_match "$email" "$RE_EMAIL" "email"
    certbot certonly --webroot -w "$ACME_WEBROOT" -d "$domain" \
        --non-interactive --agree-tos -m "$email" --no-eff-email
}

op_certbot_remove() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    certbot delete --cert-name "$domain" --non-interactive
}

# ---------------------------------------------------------------------------
# Service status (whitelist only - never arbitrary systemctl targets)
# ---------------------------------------------------------------------------
op_service_status() {
    local svc="$1"
    case "$svc" in
        nginx|mariadb|cloudflared) ;;
        php7.4-fpm|php8.0-fpm|php8.1-fpm|php8.2-fpm|php8.3-fpm|php8.4-fpm) ;;
        *) fail "Service tidak diizinkan: $svc" ;;
    esac
    systemctl is-active "$svc" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Database backup / restore (mysqldump runs as root via unix_socket auth)
# ---------------------------------------------------------------------------
op_mysqldump_db() {
    local db="$1" outfile="$2"
    require_match "$db" "$RE_DBNAME" "dbname"
    require_path_within "$outfile" "$BACKUP_BASE" >/dev/null
    mkdir -p "$(dirname "$outfile")"
    mysqldump --single-transaction --routines --triggers -u root "$db" > "$outfile"
    chown panel:panel "$outfile"
    chmod 640 "$outfile"
    echo "OK: backup ${db} -> ${outfile}"
}

op_mysql_restore_db() {
    local db="$1" infile="$2"
    require_match "$db" "$RE_DBNAME" "dbname"
    require_path_within "$infile" "$BACKUP_BASE" >/dev/null
    [[ -f "$infile" ]] || fail "File backup tidak ditemukan: $infile"
    mysql -u root "$db" < "$infile"
    echo "OK: restore ${db} <- ${infile}"
}

# ---------------------------------------------------------------------------
# Cloudflared control
# ---------------------------------------------------------------------------
op_cloudflared_status() {
    systemctl is-active cloudflared 2>/dev/null || true
}
op_cloudflared_restart() { systemctl restart cloudflared; }
op_cloudflared_stop()    { systemctl stop cloudflared; }
op_cloudflared_start()   { systemctl start cloudflared; }
op_cloudflared_version() {
    cloudflared --version 2>/dev/null | head -1 || true
}

# ---------------------------------------------------------------------------
# Filesystem helpers (confined to fixed base directories)
# ---------------------------------------------------------------------------
op_fs_mkdir_website() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    local dir="${WWW_BASE}/${domain}"
    require_path_within "$dir" "$WWW_BASE" >/dev/null
    mkdir -p "${dir}/public"
    chown -R www-data:www-data "$dir"
    chmod 750 "$dir"
    echo "$dir"
}

op_fs_remove_website() {
    local domain="$1"
    require_match "$domain" "$RE_DOMAIN" "domain"
    local dir="${WWW_BASE}/${domain}"
    require_path_within "$dir" "$WWW_BASE" >/dev/null
    [[ "$dir" == "$WWW_BASE" ]] && fail "Refusing to remove base directory"
    rm -rf -- "$dir"
    echo "OK: removed $dir"
}

op_fs_remove_nodeapp() {
    local app="$1"
    require_match "$app" "$RE_APPNAME" "appname"
    local dir="${NODEAPPS_BASE}/${app}"
    require_path_within "$dir" "$NODEAPPS_BASE" >/dev/null
    [[ "$dir" == "$NODEAPPS_BASE" ]] && fail "Refusing to remove base directory"
    rm -rf -- "$dir"
    echo "OK: removed $dir"
}

op_disk_usage() {
    # Emits: total_bytes used_bytes avail_bytes for the root filesystem.
    # Not privileged (df needs no root), but routed through this audited
    # channel for consistency - the panel PHP-FPM pool's open_basedir does
    # not include '/', so it cannot call disk_total_space() itself.
    df -B1 --output=size,used,avail / | tail -n 1
}

op_port_check() {
    local port="$1"
    require_match "$port" "$RE_PORT" "port"
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ":${port}\$"; then
        echo "listening"
    else
        echo "free"
    fi
}

# ---------------------------------------------------------------------------
# File backup / restore (tar) for website document roots and Node.js apps -
# needed because 'panel' cannot read files owned by www-data/nodeapps.
# ---------------------------------------------------------------------------
op_backup_tar_website() {
    local domain="$1" outfile="$2"
    require_match "$domain" "$RE_DOMAIN" "domain"
    require_path_within "$outfile" "$BACKUP_BASE" >/dev/null
    local src="${WWW_BASE}/${domain}"
    [[ -d "$src" ]] || fail "Direktori website tidak ditemukan: $src"
    mkdir -p "$(dirname "$outfile")"
    tar -czf "$outfile" -C "$WWW_BASE" "$domain"
    chown panel:panel "$outfile"
    chmod 640 "$outfile"
    echo "OK: backup ${domain} -> ${outfile}"
}

op_backup_tar_nodeapp() {
    local app="$1" outfile="$2"
    require_match "$app" "$RE_APPNAME" "appname"
    require_path_within "$outfile" "$BACKUP_BASE" >/dev/null
    local src="${NODEAPPS_BASE}/${app}"
    [[ -d "$src" ]] || fail "Direktori aplikasi tidak ditemukan: $src"
    mkdir -p "$(dirname "$outfile")"
    tar -czf "$outfile" -C "$NODEAPPS_BASE" "$app"
    chown panel:panel "$outfile"
    chmod 640 "$outfile"
    echo "OK: backup ${app} -> ${outfile}"
}

op_restore_tar_website() {
    local infile="$1" domain="$2"
    require_match "$domain" "$RE_DOMAIN" "domain"
    require_path_within "$infile" "$BACKUP_BASE" >/dev/null
    [[ -f "$infile" ]] || fail "File backup tidak ditemukan: $infile"
    tar -xzf "$infile" -C "$WWW_BASE"
    chown -R www-data:www-data "${WWW_BASE}/${domain}"
    echo "OK: restore ${domain} <- ${infile}"
}

op_restore_tar_nodeapp() {
    local infile="$1" app="$2"
    require_match "$app" "$RE_APPNAME" "appname"
    require_path_within "$infile" "$BACKUP_BASE" >/dev/null
    [[ -f "$infile" ]] || fail "File backup tidak ditemukan: $infile"
    tar -xzf "$infile" -C "$NODEAPPS_BASE"
    chown -R nodeapps:nodeapps "${NODEAPPS_BASE}/${app}"
    echo "OK: restore ${app} <- ${infile}"
}

# ---------------------------------------------------------------------------
# Cron job files - written as discrete /etc/cron.d/ files (one per job id),
# never by editing a shared crontab in place.
# ---------------------------------------------------------------------------
RE_CRONID='^panel-[0-9]+$'

op_cron_write() {
    local jobid="$1"
    require_match "$jobid" "$RE_CRONID" "cron job id"
    local target="/etc/cron.d/${jobid}"
    local tmp
    tmp=$(mktemp)
    cat > "$tmp"
    [[ -s "$tmp" ]] || { rm -f "$tmp"; fail "Konten cron kosong"; }
    mv "$tmp" "$target"
    chown root:root "$target"
    chmod 644 "$target"
    echo "OK: cron ${jobid} written"
}

op_cron_delete() {
    local jobid="$1"
    require_match "$jobid" "$RE_CRONID" "cron job id"
    rm -f "/etc/cron.d/${jobid}"
    echo "OK: cron ${jobid} removed"
}

# ---------------------------------------------------------------------------
# Log tail - whitelisted log keys only, mapped internally to fixed paths.
# ---------------------------------------------------------------------------
op_log_tail() {
    local logkey="$1" lines="${2:-200}"
    require_match "$lines" "$RE_LINES" "lines"
    [[ "$lines" -le 2000 ]] || lines=2000

    local path=""
    case "$logkey" in
        nginx-access:*)
            local d="${logkey#nginx-access:}"
            require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-access.log"
            ;;
        nginx-error:*)
            local d="${logkey#nginx-error:}"
            require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-error.log"
            ;;
        phpfpm-error:*)
            local v="${logkey#phpfpm-error:}"
            case "$v" in
                7.4|8.0|8.1|8.2|8.3|8.4) ;;
                *) fail "Versi PHP tidak diizinkan: $v" ;;
            esac
            path="/var/log/php${v}-fpm.log"
            ;;
        deployment)
            path="/var/log/yuuka-installer/deployment.log"
            ;;
        *) fail "Log key tidak dikenal: $logkey" ;;
    esac

    [[ -f "$path" ]] || { echo ""; return 0; }
    tail -n "$lines" "$path"
}

op_log_clear() {
    local logkey="$1"
    # Reuse the same whitelist/path resolution as op_log_tail by calling it
    # with 0 lines is not safe (still reads); resolve path again explicitly.
    local path=""
    case "$logkey" in
        nginx-access:*)
            local d="${logkey#nginx-access:}"; require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-access.log" ;;
        nginx-error:*)
            local d="${logkey#nginx-error:}"; require_match "$d" "$RE_DOMAIN" "domain"
            path="/var/log/nginx/${d}-error.log" ;;
        *) fail "Log key tidak dapat dikosongkan: $logkey" ;;
    esac
    [[ -f "$path" ]] && : > "$path"
    echo "OK: cleared $logkey"
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------
SUBCOMMAND="${1:-}"
[[ -n "$SUBCOMMAND" ]] || { echo "Usage: panel-exec.sh <subcommand> [args...]" >&2; exit 2; }
shift || true

case "$SUBCOMMAND" in
    nginx-test)            op_nginx_test ;;
    nginx-reload)          op_nginx_reload ;;
    nginx-write-config)    op_nginx_write_config "$@" ;;
    nginx-enable)          op_nginx_enable "$@" ;;
    nginx-disable)         op_nginx_disable "$@" ;;
    nginx-delete)          op_nginx_delete "$@" ;;
    pm2-deploy)            op_pm2_deploy "$@" ;;
    pm2-start)             op_pm2_start "$@" ;;
    pm2-stop)              op_pm2_stop "$@" ;;
    pm2-restart)           op_pm2_restart "$@" ;;
    pm2-reload)            op_pm2_reload "$@" ;;
    pm2-delete)            op_pm2_delete "$@" ;;
    pm2-jlist)             op_pm2_jlist ;;
    pm2-describe)          op_pm2_describe "$@" ;;
    pm2-logs)              op_pm2_logs "$@" ;;
    pm2-flush)             op_pm2_flush "$@" ;;
    pm2-save)              op_pm2_save ;;
    certbot-issue)         op_certbot_issue "$@" ;;
    certbot-remove)        op_certbot_remove "$@" ;;
    service-status)        op_service_status "$@" ;;
    mysqldump-db)          op_mysqldump_db "$@" ;;
    mysql-restore-db)      op_mysql_restore_db "$@" ;;
    cloudflared-status)    op_cloudflared_status ;;
    cloudflared-restart)   op_cloudflared_restart ;;
    cloudflared-stop)      op_cloudflared_stop ;;
    cloudflared-start)     op_cloudflared_start ;;
    cloudflared-version)   op_cloudflared_version ;;
    disk-usage)            op_disk_usage ;;
    fs-mkdir-website)      op_fs_mkdir_website "$@" ;;
    fs-remove-website)     op_fs_remove_website "$@" ;;
    fs-remove-nodeapp)     op_fs_remove_nodeapp "$@" ;;
    port-check)            op_port_check "$@" ;;
    backup-tar-website)    op_backup_tar_website "$@" ;;
    backup-tar-nodeapp)    op_backup_tar_nodeapp "$@" ;;
    restore-tar-website)   op_restore_tar_website "$@" ;;
    restore-tar-nodeapp)   op_restore_tar_nodeapp "$@" ;;
    cron-write)            op_cron_write "$@" ;;
    cron-delete)           op_cron_delete "$@" ;;
    log-tail)              op_log_tail "$@" ;;
    log-clear)             op_log_clear "$@" ;;
    *)
        echo "ERROR: subcommand tidak dikenal: ${SUBCOMMAND}" >&2
        audit "$SUBCOMMAND" "rejected:unknown-subcommand"
        exit 2
        ;;
esac

audit "$SUBCOMMAND" "ok"
