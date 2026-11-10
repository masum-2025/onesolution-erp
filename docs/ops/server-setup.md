# Server setup: localhost (Laragon) আর আসল host

এই guide-এ দুটো অংশ:

- **A. Localhost:** নিজের Windows কম্পিউটারে Laragon দিয়ে দ্রুত চালানো।
- **B. আসল host:** Ubuntu VPS-এ production চালানো।

আরও বিস্তারিত এই ফাইলগুলোতে:

- [server-hardening.md](server-hardening.md): নিরাপত্তা
- [db-least-privilege.md](db-least-privilege.md): database user
- [sites-domains.md](sites-domains.md): partner আর স্কুলের নিজের domain
- [backups.md](backups.md): backup

> কেন দরকার: `php artisan serve` একসাথে মাত্র **একটা** অনুরোধ সামলায়। একটা পাতা ৫–৬টা API ডাকলে সেগুলো
> লাইনে দাঁড়িয়ে থাকে, তাই app ধীর লাগে। nginx বা Apache আর PHP-FPM অনেকগুলো অনুরোধ একসাথে চালায়।

---

## A. Localhost (Windows + Laragon)

### A1. `artisan serve`-এর বদলে Laragon-এর web server

1. Laragon → **Menu → Preferences → General → Document Root**: `C:\laragon\www`।
2. **Auto virtual hosts** চালু রাখুন। প্রতিটা ফোল্ডার নিজে থেকেই `<ফোল্ডার>.test` ঠিকানা পায়।
   এখানে ঠিকানা হবে `http://onesolution-erp.test`, আর এর document root `public/` ফোল্ডার।
3. Laragon → **Menu → Apache** অথবা **Nginx** বেছে নিন। দুটোই PHP-FPM বা mod_php দিয়ে একসাথে অনেক অনুরোধ চালায়।
4. **Start All** চাপুন, এতে MySQL, Redis আর web server চালু হবে।
5. `.env`-এ এগুলো লিখুন:

   ```dotenv
   APP_URL=http://onesolution-erp.test
   SESSION_DOMAIN=onesolution-erp.test
   SANCTUM_STATEFUL_DOMAINS=onesolution-erp.test,localhost,127.0.0.1
   ```

6. `php artisan config:clear` চালান, তারপর browser-এ `http://onesolution-erp.test` খুলুন।
   এরপর আর `artisan serve` চালানোর দরকার নেই।

### A2. Redis দিয়ে cache, session আর queue

Laragon-এর সাথেই Redis আসে (**Menu → Redis**)। `.env`-এ:

```dotenv
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

PHP-তে `redis` extension চালু থাকতে হবে (Laragon → **PHP → Extensions → redis**)। চালু না থাকলে লিখুন
`REDIS_CLIENT=predis`, আর একবার `composer require predis/predis` চালান।

### A3. OPcache চালু করা

Laragon → **PHP → php.ini**-তে এগুলো লিখে Laragon restart করুন:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=50000
; dev: code বদলালে সাথে সাথে ধরবে
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

### A4. Queue worker আর scheduler (দুটো আলাদা terminal-এ)

```bash
php artisan queue:work --tries=3 --timeout=120   # ধীর কাজ (Facebook বার্তা, courier booking, export) background-এ
php artisan schedule:work                         # নির্দিষ্ট সময়ের কাজ (payment মেলানো, backup, Facebook post প্রকাশ)
```

Code বদলালে `php artisan queue:restart` চালান, নাহলে worker পুরোনো code চালাতে থাকবে।

### A5. Frontend

- কাজ করার সময়: `npm run dev`। পাতা save করলেই browser-এ নতুন code আসবে।
- আসল build দেখতে চাইলে: `npm run build`।

### A6. Dev-এ কী করবেন না

- `php artisan optimize` dev-এ চালাবেন না। এতে `.env` বা route বদলালেও পুরোনো setting cache থেকে চলবে।
  ভুল করে চালালে `php artisan optimize:clear` দিন।
- C: ড্রাইভে অন্তত ১০ GB খালি রাখুন। জায়গা কম থাকলে MySQL ধীরে চালু হয় আর মাঝে মাঝে বন্ধ হয়ে যায়।

---

## B. আসল host (Ubuntu 24.04 VPS)

Shared hosting (cPanel) **পরামর্শ নয়**। সেখানে queue worker, Supervisor আর Redis পাওয়া যায় না,
আর partner-এর নিজের domain-এর জন্য দরকারমতো certificate বানানোও যায় না।

### B1. Server-এর মাপ (শুরুতে)

| কত ক্লায়েন্ট | Server | কী আলাদা |
|---|---|---|
| শুরু (১–৫০ কোম্পানি) | 1টা VPS: 4 vCPU, 8 GB RAM, 80 GB SSD | সব এক server-এ |
| বাড়ছে | App 2টা + DB 1টা (8 GB+) + Redis | load balancer-এর পেছনে app |
| বড় ক্লায়েন্ট | আলাদা tenant DB | `TENANT_DATABASES`, `php artisan tenants:move` |

### B2. Package বসানো

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server supervisor git unzip curl
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-redis php8.3-opcache
# sodium আর openssl PHP 8.3-এর সাথেই আসে (backup-এর জন্য লাগে)
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && sudo apt install -y nodejs
```

### B3. ব্যবহারকারী আর ফোল্ডার

```bash
sudo adduser --disabled-password deploy
sudo mkdir -p /var/www/onesolution-erp && sudo chown deploy:www-data /var/www/onesolution-erp
sudo -u deploy git clone <repo-url> /var/www/onesolution-erp
cd /var/www/onesolution-erp
sudo chown -R deploy:www-data storage bootstrap/cache && sudo chmod -R ug+rwX storage bootstrap/cache
```

PHP-FPM, queue worker আর scheduler সবই একই সাধারণ ব্যবহারকারী দিয়ে চলবে, কখনো root দিয়ে নয়।

### B4. Database

[db-least-privilege.md](db-least-privilege.md) অনুযায়ী দুটো user বানান:

- `erp_app`: app-এর জন্য, শুধু ডেটা পড়া আর লেখা।
- `erp_migrate`: শুধু deploy-এর জন্য, table বদলাতে পারে।

MySQL শুধু private network বা `127.0.0.1`-এ শুনবে। Redis-এ password দিন, আর `protected-mode yes` রাখুন।

### B5. `.env` (production)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=onesolution_erp
DB_USERNAME=erp_app
DB_PASSWORD=<লম্বা random password>

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_PASSWORD=<password>

SESSION_DOMAIN=app.example.com
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SANCTUM_STATEFUL_DOMAINS=app.example.com
TRUSTED_PROXIES=<load balancer-এর IP, না থাকলে ফাঁকা>

MAIL_MAILER=smtp            # আসল mail server
HEALTH_TOKEN=<random>       # monitoring /internal/health পড়বে
BACKUP_KEY_V1=<base64 key>  # backups.md দেখুন
ANTHROPIC_API_KEY=...       # AI (ইচ্ছামতো)
STEADFAST_URL=https://portal.packzy.com/api/v1
```

`php artisan key:generate` একবার চালান। `.env` ফাইল git-এ কখনো রাখবেন না।

### B6. প্রথম deploy আর প্রতিবারের deploy

```bash
cd /var/www/onesolution-erp
php artisan down --retry=30                 # প্রথমবার এই লাইন বাদ দিন
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build                     # অথবা CI-তে build করে public/build পাঠান
# table বদল: erp_migrate user দিয়ে (আলাদা env-এ)
DB_USERNAME=erp_migrate DB_PASSWORD=... php artisan migrate --force
DB_USERNAME=erp_migrate DB_PASSWORD=... php artisan tenants:migrate --force   # আলাদা tenant DB থাকলে
php artisan rules:sync
php artisan access:sync
php artisan accounting:map-postings
php artisan addons:sync-prices
php artisan optimize                        # config, route, event আর view cache
php artisan queue:restart                   # worker নতুন code নেবে
sudo systemctl reload php8.3-fpm            # OPcache নতুন code পড়বে
php artisan up
php artisan security:check                  # কোনো setting অনিরাপদ হলে এখানে ব্যর্থ হবে
```

`tenants:migrate` কোন flag নেয় তা `php artisan tenants:migrate --help` দিয়ে দেখে নিন।

### B7. PHP-FPM আর OPcache (production)

`/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
pm = dynamic
pm.max_children = 20        ; RAM (MB) ÷ ~60; 8 GB server-এ ~20–40
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 10
pm.max_requests = 1000
```

`/etc/php/8.3/fpm/conf.d/10-opcache.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=50000
opcache.validate_timestamps=0   ; production: deploy-এর পর FPM reload করতে হবে (B6)
opcache.jit=tracing
opcache.jit_buffer_size=64M
```

### B8. nginx

`/etc/nginx/sites-available/onesolution-erp`:

```nginx
server {
    listen 80;
    server_name app.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name app.example.com;
    root /var/www/onesolution-erp/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/app.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    client_max_body_size 20m;

    # ফাইল ছোট করে পাঠানো
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;
    gzip_min_length 1024;

    # build ফাইলের নামে hash থাকে, তাই browser এক বছর মনে রাখতে পারে
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/onesolution-erp /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d app.example.com      # certificate, নিজে থেকেই নবায়ন হয়
```

**Partner আর স্কুলের নিজের domain:** এগুলোর certificate প্রথমবার visit-এর সময় বানাতে হয়। এর জন্য nginx-এর বদলে বা
সামনে **Caddy** ব্যবহার করুন। Caddy certificate বানানোর আগে app-কে জিজ্ঞেস করে domain-টা আমাদের কিনা:
`/internal/tls/ask`, আর স্কুলের সাইটের জন্য `/s/_tls`। নমুনা config আছে
[sites-domains.md](sites-domains.md)-এ।

### B9. Queue worker (Supervisor)

`/etc/supervisor/conf.d/onesolution-worker.conf`:

```ini
[program:onesolution-worker]
command=php /var/www/onesolution-erp/artisan queue:work redis --sleep=1 --tries=3 --timeout=120 --max-time=3600
user=deploy
numprocs=3
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=130
redirect_stderr=true
stdout_logfile=/var/www/onesolution-erp/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

### B10. Scheduler (cron)

`sudo crontab -u deploy -e`, তারপর এই লাইন:

```cron
* * * * * cd /var/www/onesolution-erp && php artisan schedule:run >> /dev/null 2>&1
```

এটা না চললে অনেক কাজ থেমে যাবে:

- payment মেলানো (প্রতি ৫ মিনিটে)
- support access শেষ হওয়া (প্রতি মিনিটে)
- billing (মাসে একবার)
- backup (রাত 1:30)
- audit log পাঠানো
- monitoring heartbeat

বাকি তালিকা আছে `routes/console.php`-এ।
Heartbeat থেমে গেলে `health:report` সতর্ক করে।

### B11. বাইরের service-এর webhook (HTTPS লাগবেই)

| Service | ঠিকানা |
|---|---|
| Facebook (Meta app) | `https://app.example.com/api/meta/{app}/webhook` |
| Courier (Steadfast) | `https://app.example.com/api/couriers/{organization}/{courier}/webhook` |
| Payment gateway (SSLCommerz) | callback ঠিকানা app-এর merchant account পাতায় দেখায় |

### B12. Firewall আর monitoring

```bash
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

- MySQL (3306) আর Redis (6379) বাইরের internet থেকে খোলা রাখা যাবে না।
- Uptime monitor (যেমন UptimeRobot) দিয়ে `https://app.example.com/internal/health` পড়ুন, header-এ
  `Authorization: Bearer <HEALTH_TOKEN>` দিয়ে।
- Backup: রাতে নিজে থেকে চলে (`backup:run`)। মাসে একবার backup ফিরিয়ে এনে মিলিয়ে দেখা হয় (`backup:restore-drill`)।
  Backup অন্য server বা S3-এ রাখুন ([backups.md](backups.md))।

### B13. চালু করার পর একবার মিলিয়ে দেখুন

- [ ] `php artisan security:check` সফল
- [ ] `https://` ঠিকানায় login হয়, পাতা বদলালে আবার login চায় না (cookie আর session domain ঠিক আছে)
- [ ] `sudo supervisorctl status`-এ ৩টা worker **RUNNING**
- [ ] ১–২ মিনিট পরে `storage/logs/laravel.log`-এ scheduler-এর কোনো error নেই
- [ ] একটা test mail আর SMS যায়
- [ ] Facebook আর courier webhook-এ test notice এলে 200 আসে
- [ ] `/build/` ফাইলের উত্তরে `Cache-Control: public, immutable` আর `Content-Encoding: gzip` আছে
- [ ] Backup ফাইল তৈরি হয়েছে, আর restore drill একবার চালিয়ে দেখা হয়েছে
