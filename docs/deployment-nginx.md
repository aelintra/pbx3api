# Nginx deployment for pbx3api

This project now ships an nginx site definition for serving the API on HTTPS port `44300`.

## Ownership model

- `pbx3api` owns HTTP serving for the API (`nginx` site config and lifecycle).
- `pbx3` owns certificate acquisition and renewal (Let's Encrypt), and nginx only references those cert paths.

## Files added

- Nginx site config: `config/nginx/pbx3-api.conf`
- Installer entrypoint: `scripts/installer.sh`
- Install helper: `scripts/install-nginx-site.sh`
- Remove helper: `scripts/remove-nginx-site.sh`

## Runtime package requirements

Minimum required packages on Ubuntu 24.04:

- `nginx`
- `php8.3-fpm`
- Laravel/PHP extensions used by pbx3api (for example: `php8.3-curl`, `php8.3-mbstring`, `php8.3-xml`, `php8.3-sqlite3`, `php8.3-mysql`, `php8.3-zip`, `php8.3-ldap`, `php8.3-gd`)

## Install and enable API site

Run as root on the target host:

```sh
sh /opt/pbx3api/scripts/installer.sh
```

The installer supports clone-based testing and now performs:

1. `apt-get` install of runtime packages (`nginx`, `composer`, `php8.3-fpm`, and required PHP extensions)
2. `composer install` (if `vendor/autoload.php` is missing)
3. nginx site deployment via `install-nginx-site.sh`

Optional environment flags:

- `SKIP_APT=1` to skip package installation
- `SKIP_COMPOSER=1` to skip composer install
- `PHP_VERSION=8.3` (default) to change php package/version suffix
- `PHP_FPM_SERVICE=php8.3-fpm` to force specific FPM service name

For direct helper usage:

```sh
sh /opt/pbx3api/scripts/install-nginx-site.sh
```

What it does:

1. Creates `/opt/pbx3api/public` if needed.
2. Copies site file to `/etc/nginx/sites-available/pbx3-api.conf`.
3. Enables site via `/etc/nginx/sites-enabled/pbx3-api.conf`.
4. Validates nginx config (`nginx -t`).
5. Enables/starts `php8.3-fpm` and reloads nginx.

## Remove/disable API site

```sh
sh /opt/pbx3api/scripts/remove-nginx-site.sh
```

This removes the enabled symlink and reloads nginx.

## TLS certificate paths

Default config uses snakeoil certificates so first boot can succeed. When `pbx3` provisions Let's Encrypt certificates, switch to:

- `/etc/letsencrypt/live/<fqdn>/fullchain.pem`
- `/etc/letsencrypt/live/<fqdn>/privkey.pem`

`pbx3api` does not run certbot and does not own ACME workflow.
