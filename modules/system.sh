#!/usr/bin/env bash
# ==============================================================================
# system.sh - Base system checks, updates, dependencies, dedicated OS users
# ==============================================================================

module_system_check_root() {
    require_root
}

module_system_check_ubuntu() {
    log_step "Memeriksa distribusi dan versi Ubuntu"

    if [[ ! -f /etc/os-release ]]; then
        die "Tidak dapat mendeteksi distribusi OS (/etc/os-release tidak ditemukan)"
    fi

    # shellcheck disable=SC1091
    source /etc/os-release

    if [[ "${ID:-}" != "ubuntu" ]]; then
        die "OS terdeteksi: ${PRETTY_NAME:-unknown}. Script ini hanya didukung di Ubuntu Server."
    fi

    UBUNTU_VERSION="${VERSION_ID:-unknown}"
    UBUNTU_CODENAME="${VERSION_CODENAME:-unknown}"

    case "$UBUNTU_VERSION" in
        20.04|22.04|24.04)
            log_ok "Ubuntu ${UBUNTU_VERSION} (${UBUNTU_CODENAME}) didukung penuh"
            ;;
        *)
            log_warn "Ubuntu ${UBUNTU_VERSION} belum diverifikasi secara resmi. Instalasi tetap dilanjutkan, tapi beberapa PPA mungkin tidak tersedia."
            confirm "Lanjutkan instalasi pada Ubuntu ${UBUNTU_VERSION}?" "N" || die "Instalasi dibatalkan oleh user."
            ;;
    esac

    export UBUNTU_VERSION UBUNTU_CODENAME
    state_mark "system:ubuntu_checked"
}

module_system_update() {
    log_step "Update package index & sistem"
    spinner_run "apt-get update" -- apt-get update -y
    spinner_run "apt-get upgrade (non-interaktif)" -- env DEBIAN_FRONTEND=noninteractive apt-get upgrade -y
    state_mark "system:updated"
}

module_system_dependencies() {
    log_step "Install dependency dasar"
    export DEBIAN_FRONTEND=noninteractive
    apt_install software-properties-common apt-transport-https ca-certificates \
        curl wget gnupg2 lsb-release unzip zip tar git jq \
        build-essential ufw cron logrotate sysstat net-tools \
        openssl whois
    state_mark "system:dependencies"
}

module_system_timezone() {
    log_step "Konfigurasi timezone"
    if [[ "$(timedatectl show -p Timezone --value 2>/dev/null)" != "UTC" ]] && [[ -z "${SKIP_TZ:-}" ]]; then
        log_info "Timezone saat ini: $(timedatectl show -p Timezone --value 2>/dev/null || echo unknown)"
    fi
    timedatectl set-ntp true >/dev/null 2>&1 || true
    log_ok "NTP sinkronisasi diaktifkan"
}

module_system_firewall() {
    log_step "Konfigurasi firewall dasar (UFW)"

    if ! command_exists ufw; then
        log_warn "ufw tidak tersedia, lewati konfigurasi firewall"
        return 0
    fi

    ufw allow OpenSSH >>"$INSTALL_LOG_FILE" 2>&1 || true
    ufw allow 80/tcp >>"$INSTALL_LOG_FILE" 2>&1 || true
    ufw allow 443/tcp >>"$INSTALL_LOG_FILE" 2>&1 || true

    if ufw status | grep -q "Status: active"; then
        log_ok "UFW sudah aktif, rule ditambahkan/dipastikan ada"
    else
        if confirm "UFW belum aktif. Aktifkan firewall sekarang (allow SSH, 80, 443)?" "Y"; then
            ufw --force enable >>"$INSTALL_LOG_FILE" 2>&1
            log_ok "UFW diaktifkan"
        else
            log_warn "UFW tidak diaktifkan. Server tidak terlindungi firewall lokal."
        fi
    fi
    state_mark "system:firewall"
}

# ---------------------------------------------------------------------------
# Dedicated OS users for privilege separation
#   panel     -> runs panel's own PHP-FPM pool
#   nodeapps  -> owns the single PM2 daemon that runs all Node.js apps
# ---------------------------------------------------------------------------
module_system_create_users() {
    log_step "Membuat user sistem khusus (privilege separation)"

    if id "panel" &>/dev/null; then
        log_ok "User sistem 'panel' sudah ada"
    else
        useradd --system --create-home --home-dir /opt/server-panel --shell /usr/sbin/nologin panel
        log_ok "User sistem 'panel' dibuat"
    fi

    if id "nodeapps" &>/dev/null; then
        log_ok "User sistem 'nodeapps' sudah ada"
    else
        useradd --system --create-home --home-dir /home/nodeapps --shell /bin/bash nodeapps
        log_ok "User sistem 'nodeapps' dibuat (menjalankan seluruh proses PM2)"
    fi

    mkdir -p /var/www
    chown root:root /var/www
    chmod 755 /var/www

    state_mark "system:users_created"
}

module_system_run_all() {
    module_system_check_root
    module_system_check_ubuntu
    module_system_update
    module_system_dependencies
    module_system_timezone
    module_system_firewall
    module_system_create_users
}
