# pbx3api

Laravel 11 API service for `pbx3`.

## Webserver ownership

`pbx3api` owns the API web interface (nginx + PHP-FPM) and serves HTTPS on port `44300`.

- Nginx site config: `config/nginx/pbx3-api.conf`
- Installer entrypoint: `scripts/installer.sh`
- Install helper: `scripts/install-nginx-site.sh`
- Remove helper: `scripts/remove-nginx-site.sh`
- Deployment guide: `docs/deployment-nginx.md`

The installer is intended to be one-command on Ubuntu 24.04:

```sh
sudo sh scripts/installer.sh
```

It installs runtime packages, runs `composer install` when needed, and deploys/enables the nginx site on `44300`.
It also bootstraps Laravel runtime state (`.env`, symlink to PBX sqlite DB, writable cache/log dirs, artisan cache/key commands).
Installer ownership model keeps code as `root:root` and grants write access only to Laravel runtime dirs.

## Certificate ownership

TLS certificate acquisition/renewal is owned by `pbx3` (host layer), not `pbx3api`.
The nginx site in this repo references certificate paths and can use snakeoil by default until Let's Encrypt certs are present.

## Development notes

This is a standard Laravel application (`php` 8.3+ on Ubuntu 24.04 target hosts). Existing API docs are in `docs/`.
