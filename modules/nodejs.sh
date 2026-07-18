#!/usr/bin/env bash
# ==============================================================================
# nodejs.sh - Install NVM, multiple Node.js versions and PM2 under a dedicated
#              'nodeapps' OS user. PM2 is the single process manager for every
#              Node.js application created through the panel.
# ==============================================================================

NODEAPPS_HOME="/home/nodeapps"
NVM_DIR="${NODEAPPS_HOME}/.nvm"
NODE_VERSIONS=("18" "20" "22")
NODE_DEFAULT_VERSION="20"

as_nodeapps() {
    # Run a command as the nodeapps user with NVM loaded into the shell
    runuser -u nodeapps -- bash -lc "export NVM_DIR='${NVM_DIR}'; [ -s \"\$NVM_DIR/nvm.sh\" ] && . \"\$NVM_DIR/nvm.sh\"; $*"
}

module_nodejs_install_nvm() {
    log_step "Install NVM untuk user nodeapps"

    if [[ -s "${NVM_DIR}/nvm.sh" ]]; then
        log_ok "NVM sudah terinstall di ${NVM_DIR}"
    else
        local nvm_version="v0.39.7"
        spinner_run "Download & install NVM ${nvm_version}" -- \
            runuser -u nodeapps -- bash -c "curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/${nvm_version}/install.sh | bash"
    fi

    grep -q "NVM_DIR" "${NODEAPPS_HOME}/.bashrc" 2>/dev/null || {
        cat >> "${NODEAPPS_HOME}/.bashrc" <<EOF

export NVM_DIR="${NVM_DIR}"
[ -s "\$NVM_DIR/nvm.sh" ] && \. "\$NVM_DIR/nvm.sh"
[ -s "\$NVM_DIR/bash_completion" ] && \. "\$NVM_DIR/bash_completion"
EOF
        chown nodeapps:nodeapps "${NODEAPPS_HOME}/.bashrc"
    }

    state_mark "nodejs:nvm_installed"
}

module_nodejs_install_versions() {
    log_step "Install Node.js versions (18, 20, 22)"

    local v
    for v in "${NODE_VERSIONS[@]}"; do
        if as_nodeapps "nvm ls ${v} >/dev/null 2>&1"; then
            log_ok "Node.js ${v} sudah terinstall"
        else
            spinner_run "Install Node.js ${v} via NVM" -- as_nodeapps "nvm install ${v}"
        fi
    done

    ask NODE_DEFAULT_VERSION "Pilih versi Node.js default (digunakan oleh PM2 daemon)" "$NODE_DEFAULT_VERSION"
    as_nodeapps "nvm alias default ${NODE_DEFAULT_VERSION}" >>"$INSTALL_LOG_FILE" 2>&1 || \
        log_warn "Gagal set alias default Node.js, gunakan versi tertinggi yang terinstall"

    export NODE_DEFAULT_VERSION
    state_mark "nodejs:versions_installed"
}

module_nodejs_install_pm2() {
    log_step "Install PM2 (process manager global untuk nodeapps)"

    if as_nodeapps "command -v pm2 >/dev/null 2>&1"; then
        log_ok "PM2 sudah terinstall"
    else
        spinner_run "npm install -g pm2" -- as_nodeapps "npm install -g pm2"
    fi

    mkdir -p "${NODEAPPS_HOME}/apps"
    chown -R nodeapps:nodeapps "${NODEAPPS_HOME}/apps"

    state_mark "nodejs:pm2_installed"
}

module_nodejs_configure_startup() {
    log_step "Konfigurasi PM2 startup (auto-run setelah reboot)"

    local node_bin_dir
    node_bin_dir=$(as_nodeapps "dirname \$(nvm which default)" 2>/dev/null | tail -1)

    if [[ -z "$node_bin_dir" ]]; then
        log_warn "Tidak dapat menentukan path Node.js default, startup PM2 dilewati"
        return 1
    fi

    local startup_cmd
    startup_cmd=$(as_nodeapps "pm2 startup systemd -u nodeapps --hp ${NODEAPPS_HOME} 2>&1 | grep '^sudo ' || true")

    if [[ -n "$startup_cmd" ]]; then
        # Strip the leading "sudo " since we are already root
        local exec_cmd="${startup_cmd#sudo }"
        spinner_run "Mengaktifkan systemd unit PM2" -- bash -c "$exec_cmd"
    else
        log_warn "PM2 tidak menghasilkan perintah startup baru (mungkin sudah dikonfigurasi)"
    fi

    as_nodeapps "pm2 save" >>"$INSTALL_LOG_FILE" 2>&1 || true

    if service_exists "pm2-nodeapps"; then
        service_enable_now "pm2-nodeapps"
    fi

    log_ok "PM2 dikonfigurasi untuk autostart setelah reboot"
    state_mark "nodejs:pm2_startup"
}

module_nodejs_verify() {
    log_step "Verifikasi instalasi Node.js & PM2"
    local nv pv
    nv=$(as_nodeapps "node -v" 2>/dev/null | tail -1)
    pv=$(as_nodeapps "pm2 -v" 2>/dev/null | tail -1)
    log_info "Node.js aktif: ${nv:-unknown}"
    log_info "PM2 version : ${pv:-unknown}"
    as_nodeapps "pm2 list" >>"$INSTALL_LOG_FILE" 2>&1 || true
}

module_nodejs_run_all() {
    module_nodejs_install_nvm
    module_nodejs_install_versions
    module_nodejs_install_pm2
    module_nodejs_configure_startup
    module_nodejs_verify
}
