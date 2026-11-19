#!/bin/bash
# /root/apps-vendas-certbot.sh (2026-11-19): roda pelo cron a cada 10 min até
# os dois subdomínios dos apps de vendas terem certificado. Quando o DNS de
# app.kazakora.com / app.ferrakora.com aponta para cá (ou para o Cloudflare,
# com proxy), tira o certificado com o certbot --nginx (mesmo esquema do
# painel.kazakora.com). Com os dois prontos, troca /app/<loja>/ do painel por
# redirect 301 e se tira do cron.
set -u
LOG=/var/log/apps-vendas-certbot.log
for d in app.kazakora.com app.ferrakora.com; do
    [ -d "/etc/letsencrypt/live/$d" ] && continue
    if [ -n "$(dig +short "$d" A @1.1.1.1)" ]; then
        echo "$(date -Is) DNS de $d resolve, pedindo certificado" >> "$LOG"
        certbot --nginx -d "$d" --non-interactive --agree-tos --redirect >> "$LOG" 2>&1 \
            && echo "$(date -Is) certificado de $d ok" >> "$LOG"
    fi
done
if [ -d /etc/letsencrypt/live/app.kazakora.com ] && [ -d /etc/letsencrypt/live/app.ferrakora.com ]; then
    if ! grep -q "return 301 https://app.kazakora.com" /etc/nginx/snippets/painel-apps-vendas.conf; then
        cp /etc/nginx/snippets/painel-apps-vendas.conf /root/deploy-backups/painel-apps-vendas.conf.$(date +%Y%m%d%H%M) 2>/dev/null
        cp /root/apps-vendas/nginx-painel-apps-vendas-redirect.conf /etc/nginx/snippets/painel-apps-vendas.conf
        if nginx -t >> "$LOG" 2>&1; then systemctl reload nginx; echo "$(date -Is) painel /app/* agora redireciona" >> "$LOG"; fi
    fi
    crontab -l | grep -v apps-vendas-certbot.sh | crontab -
    echo "$(date -Is) pronto, saiu do cron" >> "$LOG"
fi
