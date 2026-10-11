# Produção — loja kazakora.com na VPS

VPS Hostinger `187.127.60.51` (srv1916599, Ubuntu 22.04, 4 vCPU / 15 GB).
Divide a máquina com o **KoraSync** (nginx `korasync`, node `:3101`) e a
**Naia** (`:8787`). Nada aqui mexe neles.

## Como fica

```
/var/www/kazakora/
├── current -> releases/20261010...-abc1234   (o que está no ar)
├── releases/                                 (5 últimas versões)
└── shared/
    ├── .env
    └── storage/                              (uploads, logs, notas)
```

- nginx `kazakora.com` → PHP 8.3-FPM (pool `kazakora`, usuário `deploy`) com OPcache.
- MySQL local, Redis para cache, sessão e fila.
- Supervisor: 3 workers da fila `default` + 1 da fila `nfe` (grupo `kazakora`).
- Cron do usuário `deploy`: `schedule:run` a cada minuto.

## Instalar (uma vez, como root)

```bash
git clone --depth 1 https://github.com/unixlira/kazakora.git /root/kazakora-instalar
bash /root/kazakora-instalar/deploy/producao/provisionar.sh
```

Depois:
1. Chave da pipeline: colar a chave pública do GitHub Actions em
   `/home/deploy/.ssh/authorized_keys`.
2. `.env`: criar `/var/www/kazakora/shared/.env` a partir de
   `env-producao.exemplo` (senha do banco em `/root/kazakora-credenciais.txt`),
   `chown deploy:www-data` e `chmod 640`.
3. Primeiro deploy: push no `main` (ou "Run workflow" no GitHub).
4. HTTPS: `certbot --nginx -d kazakora.com -d www.kazakora.com --redirect`.

## Publicar

Push no `main` → GitHub Actions "Deploy (produção)": testes → build →
envio para `releases/` → migrations → troca do `current` → confere
`/up`. Se a loja não responder, volta sozinha para a versão anterior.

## Voltar versão na mão

```bash
cd /var/www/kazakora
ls -1t releases/                     # escolher a anterior
ln -sfn /var/www/kazakora/releases/<anterior> current.novo && mv -Tf current.novo current
sudo systemctl reload php8.3-fpm && php8.3 current/artisan queue:restart
```
(Migration já rodada não volta sozinha.)

## Ver se está tudo de pé

```bash
systemctl is-active php8.3-fpm mysql redis-server supervisor nginx
supervisorctl status kazakora:*
tail -f /var/www/kazakora/shared/storage/logs/laravel-*.log
```
