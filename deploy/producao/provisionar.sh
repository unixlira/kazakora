#!/usr/bin/env bash
# Prepara a VPS (srv1916599, Ubuntu 22.04) para a loja KazaKora em produção.
# Pedido 2026-10-10. Pode rodar mais de uma vez: só cria o que falta.
#
# NÃO mexe no KoraSync (nginx "korasync", node :3101) nem na Naia (:8787).
# Instala: PHP 8.3-FPM + OPcache, MySQL, Redis, Supervisor, Composer.
# Cria: usuário "deploy", /var/www/kazakora/{releases,shared}, banco e
# usuário MySQL (senha só em /root/kazakora-credenciais.txt), pool do
# PHP-FPM, workers da fila, cron do agendador e o site do nginx (http; o
# https vem depois com certbot — ver deploy/producao/LEIA-ME.md).
#
# Uso (como root na VPS):  bash provisionar.sh
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

APP=/var/www/kazakora
AQUI="$(cd "$(dirname "$0")" && pwd)"

echo "== Pacotes"
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl unzip git >/dev/null
if ! grep -rq "ondrej/php" /etc/apt/sources.list.d/ 2>/dev/null; then
    add-apt-repository -y ppa:ondrej/php >/dev/null
    apt-get update -qq
fi
apt-get install -y -qq \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-soap \
    php8.3-opcache php8.3-readline mysql-server redis-server supervisor >/dev/null
if ! command -v composer >/dev/null; then
    curl -sS https://getcomposer.org/installer | php8.3 -- --install-dir=/usr/local/bin --filename=composer >/dev/null
fi

echo "== Usuário deploy e pastas"
id deploy >/dev/null 2>&1 || useradd -m -s /bin/bash -G www-data deploy
install -d -o deploy -g www-data -m 2775 "$APP" "$APP/releases" "$APP/shared" \
    "$APP/shared/storage" "$APP/shared/storage/app/public" "$APP/shared/storage/app/private" \
    "$APP/shared/storage/framework/cache" "$APP/shared/storage/framework/sessions" \
    "$APP/shared/storage/framework/views" "$APP/shared/storage/logs"
install -d -o deploy -g deploy -m 700 /home/deploy/.ssh
touch /home/deploy/.ssh/authorized_keys && chown deploy:deploy /home/deploy/.ssh/authorized_keys && chmod 600 /home/deploy/.ssh/authorized_keys

# O deploy só pode recarregar o PHP e reiniciar os workers da loja.
cat > /etc/sudoers.d/kazakora-deploy <<'SUDO'
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, /usr/bin/supervisorctl restart kazakora\:*, /usr/bin/supervisorctl status kazakora\:*
SUDO
chmod 440 /etc/sudoers.d/kazakora-deploy
visudo -cf /etc/sudoers.d/kazakora-deploy >/dev/null

echo "== MySQL"
CRED=/root/kazakora-credenciais.txt
if [ ! -f "$CRED" ]; then
    SENHA="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 32)"
    mysql -e "CREATE DATABASE IF NOT EXISTS kazakora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS 'kazakora'@'localhost' IDENTIFIED BY '${SENHA}';"
    mysql -e "GRANT ALL PRIVILEGES ON kazakora.* TO 'kazakora'@'localhost'; FLUSH PRIVILEGES;"
    umask 077
    printf 'DB_DATABASE=kazakora\nDB_USERNAME=kazakora\nDB_PASSWORD=%s\n' "$SENHA" > "$CRED"
    echo "   senha do banco gravada em $CRED (não é mostrada aqui)"
fi
cp "$AQUI/mysql-kazakora.cnf" /etc/mysql/mysql.conf.d/zz-kazakora.cnf

echo "== Redis"
# Só escuta na própria máquina; memória limitada para não brigar com o resto.
sed -i 's/^#\? *bind .*/bind 127.0.0.1 ::1/' /etc/redis/redis.conf
sed -i 's/^#\? *maxmemory .*/maxmemory 1gb/' /etc/redis/redis.conf
grep -q '^maxmemory ' /etc/redis/redis.conf || echo 'maxmemory 1gb' >> /etc/redis/redis.conf
sed -i 's/^#\? *maxmemory-policy .*/maxmemory-policy volatile-lru/' /etc/redis/redis.conf
grep -q '^maxmemory-policy ' /etc/redis/redis.conf || echo 'maxmemory-policy volatile-lru' >> /etc/redis/redis.conf

echo "== PHP-FPM e OPcache"
cp "$AQUI/php-fpm-kazakora.conf" /etc/php/8.3/fpm/pool.d/kazakora.conf
cp "$AQUI/php-kazakora.ini" /etc/php/8.3/fpm/conf.d/90-kazakora.ini
cp "$AQUI/php-kazakora.ini" /etc/php/8.3/cli/conf.d/90-kazakora.ini
# O pool padrão (www) não é usado pela loja.
[ -f /etc/php/8.3/fpm/pool.d/www.conf ] && mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.desligado

echo "== Fila (Supervisor) e agendador (cron)"
cp "$AQUI/supervisor-kazakora.conf" /etc/supervisor/conf.d/kazakora.conf
cat > /etc/cron.d/kazakora <<'CRON'
* * * * * deploy cd /var/www/kazakora/current && php8.3 artisan schedule:run >> /dev/null 2>&1
CRON

echo "== nginx (site novo; o korasync continua como está)"
cp "$AQUI/nginx-kazakora.conf" /etc/nginx/sites-available/kazakora
ln -sf /etc/nginx/sites-available/kazakora /etc/nginx/sites-enabled/kazakora
nginx -t

systemctl enable --now php8.3-fpm mysql redis-server supervisor >/dev/null 2>&1
systemctl restart mysql redis-server php8.3-fpm
supervisorctl reread >/dev/null && supervisorctl update >/dev/null || true
systemctl reload nginx

echo "== Pronto"
for s in php8.3-fpm mysql redis-server supervisor nginx; do echo "   $s: $(systemctl is-active $s)"; done
echo "   KoraSync: HTTP $(curl -s -o /dev/null -w '%{http_code}' -H 'Host: sync.alphakora.com.br' http://127.0.0.1/)"
echo "Próximos passos: deploy/producao/LEIA-ME.md"
