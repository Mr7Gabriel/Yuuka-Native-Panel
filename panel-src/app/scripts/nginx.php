<?php
declare(strict_types=1);

/**
 * Low-level Nginx config builders + Executor wrappers. Callers (NginxService)
 * are responsible for validating domain/php-version/paths beforehand.
 */

function nginx_build_php_site_config(string $domain, string $phpVersion, string $documentRoot): string
{
    $sock = "/run/php/php{$phpVersion}-fpm.sock";

    return <<<CONF
server {
    listen 80;
    listen [::]:80;
    server_name {$domain};

    include snippets/acme-challenge.conf;
    include snippets/cloudflare-realip.conf;

    root {$documentRoot};
    index index.php index.html;

    access_log /var/log/nginx/{$domain}-access.log;
    error_log  /var/log/nginx/{$domain}-error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$sock};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) { deny all; }

    include snippets/security-headers.conf;
}
CONF;
}

function nginx_build_nodejs_proxy_config(string $domain, int $port): string
{
    return <<<CONF
server {
    listen 80;
    listen [::]:80;
    server_name {$domain};

    include snippets/acme-challenge.conf;
    include snippets/cloudflare-realip.conf;

    access_log /var/log/nginx/{$domain}-access.log;
    error_log  /var/log/nginx/{$domain}-error.log;

    location / {
        proxy_pass http://127.0.0.1:{$port};
        include snippets/proxy-params.conf;
    }

    include snippets/security-headers.conf;
}
CONF;
}

function nginx_build_ssl_server_block(string $domain, string $upstreamLocationBlock): string
{
    return <<<CONF
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {$domain};

    ssl_certificate     /etc/letsencrypt/live/{$domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$domain}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    include snippets/cloudflare-realip.conf;

    access_log /var/log/nginx/{$domain}-ssl-access.log;
    error_log  /var/log/nginx/{$domain}-ssl-error.log;

{$upstreamLocationBlock}

    include snippets/security-headers.conf;
}

server {
    listen 80;
    listen [::]:80;
    server_name {$domain};
    include snippets/acme-challenge.conf;
    location / {
        return 301 https://\$host\$request_uri;
    }
}
CONF;
}

function nginx_write_config(string $siteName, string $content): array
{
    return Executor::run('nginx-write-config', [$siteName], $content, 20);
}

function nginx_enable_site(string $siteName): array
{
    return Executor::run('nginx-enable', [$siteName], null, 20);
}

function nginx_disable_site(string $siteName): array
{
    return Executor::run('nginx-disable', [$siteName], null, 20);
}

function nginx_delete_site(string $siteName): array
{
    return Executor::run('nginx-delete', [$siteName], null, 20);
}

function nginx_reload(): array
{
    return Executor::run('nginx-reload', [], null, 20);
}
