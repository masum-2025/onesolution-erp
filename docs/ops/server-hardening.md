# Server hardening

What the servers need around the app. The app itself sends the security
headers and refuses unsafe settings (`php artisan security:check`, run on every
deploy; it fails in production while anything is unsafe).

## Network

- Only ports 443 (and 80, redirecting to 443) are open to the internet, on the
  load balancer or web server. SSH only from the admin VPN or a bastion host.
- The database (3306 / 5432) and Redis (6379) listen on the private network
  only, never on a public address, and a firewall allows them only from the app
  servers. Redis has a password (`REDIS_PASSWORD`) and `protected-mode yes`.
- Outgoing traffic from app servers: the payment gateways, mail/SMS providers,
  DNS; nothing else is needed.

## TLS and headers

- TLS 1.2+ only; certificates renewed automatically. Partner domains get
  certificates only after DNS verification (the web server asks
  `/internal/tls/ask`, which answers only for verified domains).
- The app sends `Strict-Transport-Security` on every HTTPS response
  (`SECURITY_HSTS_*`), plus `X-Content-Type-Options`, `Referrer-Policy`, and on
  pages a nonce-based Content Security Policy and `X-Frame-Options: DENY`.
- The web server passes the client address in `X-Forwarded-For` only from the
  load balancer (trusted proxies), so per-address limits and logs see the real
  address.

## PHP and the app

- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`,
  `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- The web root is `public/` only; `.env`, `storage/` and `vendor/` are outside it.
- PHP extensions needed: `sodium` (backups), `openssl`, `pdo_mysql` or
  `pdo_pgsql`, `mbstring`, `intl`.
- Queue workers and the scheduler run as the same unprivileged user as PHP-FPM,
  not root.

## Logs

- `storage/logs/security.log` (JSON, one event per line) is shipped to the log
  collector; alerts on spikes come in Phase 9.
- Logs never contain passwords, codes, tokens, emails or phone numbers
  (`tests/Feature/Security/SecurityLogTest`).
