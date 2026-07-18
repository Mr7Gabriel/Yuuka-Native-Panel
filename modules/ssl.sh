#!/usr/bin/env bash
# ==============================================================================
# ssl.sh - Certbot / Let's Encrypt installation and certificate issuance
# ==============================================================================

module_ssl_install_certbot() {
    log_step "Install Certbot (Let's Encrypt client)"

    if command_exists certbot; then
        log_ok "Certbot sudah terinstall"
    else
        apt_install certbot python3-certbot-nginx
    fi
    state_mark "ssl:certbot_installed"
}

# issue_certificate <domain> <email>
# Uses the shared webroot (${ACME_WEBROOT}) so it works with our own generated
# nginx server blocks without certbot rewriting them unpredictably.
issue_certificate() {
    local domain="$1"
    local email="$2"

    if [[ -d "/etc/letsencrypt/live/${domain}" ]]; then
        log_ok "Sertifikat untuk ${domain} sudah ada"
        return 0
    fi

    spinner_run "Menerbitkan sertifikat SSL untuk ${domain}" -- \
        certbot certonly --webroot -w "$ACME_WEBROOT" \
            -d "$domain" \
            --non-interactive --agree-tos -m "$email" \
            --no-eff-email
}

module_ssl_enable_autorenew() {
    log_step "Memastikan auto-renewal certbot aktif"

    if service_exists "certbot.timer"; then
        service_enable_now "certbot.timer"
    else
        # Fallback cron for older certbot packages without a systemd timer
        local cron_file="/etc/cron.d/certbot-renew"
        write_file_if_changed "$cron_file" <<'EOF'
0 3,15 * * * root certbot renew --quiet --deploy-hook "systemctl reload nginx"
EOF
        log_ok "Cron job renewal certbot dipasang di ${cron_file}"
    fi
    state_mark "ssl:autorenew_enabled"
}

module_ssl_prompt_for_panel() {
    local domain="$1"
    local email="$2"

    print_section "SSL untuk Panel"
    echo "Panel dapat diakses melalui HTTPS menggunakan sertifikat Let's Encrypt gratis."
    echo -e "${C_DIM}Syarat: domain '${domain}' sudah mengarah (A record) ke IP server ini.${C_RESET}"
    echo ""

    if confirm "Install SSL Let's Encrypt untuk domain panel (${domain}) sekarang?" "Y"; then
        module_ssl_install_certbot
        if issue_certificate "$domain" "$email"; then
            module_ssl_enable_autorenew
            PANEL_SSL_ENABLED="1"
            log_ok "SSL aktif untuk ${domain}"
        else
            log_warn "Penerbitan SSL gagal (kemungkinan DNS belum mengarah ke server ini). Panel tetap berjalan via HTTP, bisa dicoba lagi nanti dari dalam panel."
            PANEL_SSL_ENABLED="0"
        fi
    else
        PANEL_SSL_ENABLED="0"
        log_info "SSL dilewati. Panel dapat diaktifkan SSL kapan saja lewat menu Domain Management."
    fi
    export PANEL_SSL_ENABLED
}

module_ssl_run_all() {
    module_ssl_install_certbot
    module_ssl_enable_autorenew
}
