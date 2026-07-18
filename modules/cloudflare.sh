#!/usr/bin/env bash
# ==============================================================================
# cloudflare.sh - Optional Cloudflare Tunnel setup (network ingress only).
#                  PM2 remains the Node.js process manager regardless of mode;
#                  Cloudflare Tunnel never replaces panel authentication.
# ==============================================================================

CLOUDFLARED_CRED_DIR="/etc/cloudflared"
CLOUDFLARED_TOKEN_FILE="${CLOUDFLARED_CRED_DIR}/tunnel.token"

module_cloudflare_install_binary() {
    log_step "Install cloudflared"

    if command_exists cloudflared; then
        log_ok "cloudflared sudah terinstall ($(cloudflared --version 2>/dev/null | head -1))"
        return 0
    fi

    local arch deb_url tmp_deb
    arch=$(dpkg --print-architecture)
    deb_url="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${arch}.deb"
    tmp_deb=$(mktemp --suffix=.deb)

    if ! spinner_run "Download cloudflared (${arch})" -- curl -fsSL -o "$tmp_deb" "$deb_url"; then
        rm -f "$tmp_deb"
        log_warn "Gagal download cloudflared. Cloudflare Tunnel dilewati."
        return 1
    fi

    spinner_run "Install paket cloudflared" -- dpkg -i "$tmp_deb"
    rm -f "$tmp_deb"
    state_mark "cloudflare:binary_installed"
}

# Stores the tunnel token with restrictive permissions. Never logged, never
# echoed, never written to the panel database.
module_cloudflare_store_token() {
    local token="$1"

    mkdir -p "$CLOUDFLARED_CRED_DIR"
    chmod 700 "$CLOUDFLARED_CRED_DIR"

    umask 077
    printf '%s' "$token" > "$CLOUDFLARED_TOKEN_FILE"
    umask 022

    chown root:root "$CLOUDFLARED_TOKEN_FILE"
    chmod 600 "$CLOUDFLARED_TOKEN_FILE"
    log_ok "Token tunnel disimpan dengan permission 600 di ${CLOUDFLARED_TOKEN_FILE} (tidak dicatat ke log)"
}

module_cloudflare_install_service() {
    log_step "Konfigurasi systemd service cloudflared"

    write_file_if_changed "/etc/systemd/system/cloudflared.service" <<EOF
[Unit]
Description=Cloudflare Tunnel (yuuka panel ingress)
After=network-online.target
Wants=network-online.target

[Service]
Type=notify
ExecStart=/usr/bin/cloudflared --no-autoupdate tunnel run --token \$(cat ${CLOUDFLARED_TOKEN_FILE})
Restart=on-failure
RestartSec=5
User=root
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${CLOUDFLARED_CRED_DIR}

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    service_enable_now cloudflared

    if systemctl is-active --quiet cloudflared; then
        log_ok "cloudflared service aktif dan terhubung"
    else
        log_warn "cloudflared service gagal start. Cek: journalctl -u cloudflared -n 50 (token tidak akan dicetak)"
    fi

    state_mark "cloudflare:service_installed"
}

# Interactive prompt used from install.sh - only runs if user opts into
# Cloudflare Tunnel deployment mode.
module_cloudflare_prompt_setup() {
    print_section "Cloudflare Tunnel"
    echo "Cloudflare Tunnel bersifat opsional dan hanya berfungsi sebagai jalur"
    echo "jaringan (network ingress). Autentikasi panel tetap wajib berjalan terpisah."
    echo ""
    echo -e "${C_DIM}Anda memerlukan Tunnel Token dari Zero Trust Dashboard (Networks > Tunnels > Create a tunnel > Token).${C_RESET}"
    echo ""

    local token
    read -r -s -p "$(echo -e "${C_YELLOW}?${C_RESET} Masukkan Cloudflare Tunnel Token: ")" token
    echo ""

    if [[ -z "$token" ]]; then
        log_warn "Token kosong, konfigurasi Cloudflare Tunnel dibatalkan"
        return 1
    fi

    module_cloudflare_install_binary || return 1
    module_cloudflare_store_token "$token"
    module_cloudflare_install_service
}

module_cloudflare_run_all() {
    if confirm "Aktifkan Cloudflare Tunnel sebagai jalur akses panel/aplikasi?" "N"; then
        module_cloudflare_prompt_setup
    else
        log_info "Cloudflare Tunnel dilewati. Server tetap dapat diakses via IP publik + Nginx."
        return 0
    fi
}
