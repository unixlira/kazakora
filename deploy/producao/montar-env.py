#!/usr/bin/env python3
"""Monta /var/www/kazakora/shared/.env (produção) a partir do .env do homolog.

Pedido 2026-10-10. Rodar como root na VPS depois de copiar o .env do homolog
para /root/kazakora-env-homolog. Não imprime nenhum valor, só nomes.

- Mantém APP_KEY e todas as chaves das integrações (os tokens salvos no
  banco são cifrados com o APP_KEY).
- Troca o endereço https://kazakora.devlira.com.br -> https://kazakora.com
  (APP_URL, redirects e webhooks).
- Banco: MySQL local (senha de /root/kazakora-credenciais.txt).
- Redis em cache, sessão e fila.
- Desliga os caminhos da Hostinger (download de vídeo) até instalar na VPS.
"""
import os
import pwd
import grp

ORIGEM = '/root/kazakora-env-homolog'
CRED = '/root/kazakora-credenciais.txt'
DESTINO = '/var/www/kazakora/shared/.env'
HOMOLOG = 'https://kazakora.devlira.com.br'
PRODUCAO = 'https://kazakora.com'


def ler(caminho):
    pares = {}
    for linha in open(caminho, encoding='utf-8'):
        linha = linha.rstrip('\n')
        if '=' in linha and not linha.lstrip().startswith('#'):
            chave, valor = linha.split('=', 1)
            pares[chave.strip()] = valor
    return pares


linhas = open(ORIGEM, encoding='utf-8').read().splitlines()
cred = ler(CRED)

fixos = {
    'APP_ENV': 'production',
    'APP_DEBUG': 'false',
    'APP_URL': PRODUCAO,
    'LOG_CHANNEL': 'daily',
    'LOG_LEVEL': 'warning',
    'DB_CONNECTION': 'mysql',
    'DB_HOST': '127.0.0.1',
    'DB_PORT': '3306',
    'DB_DATABASE': cred['DB_DATABASE'],
    'DB_USERNAME': cred['DB_USERNAME'],
    'DB_PASSWORD': cred['DB_PASSWORD'],
    'REDIS_CLIENT': 'phpredis',
    'REDIS_HOST': '127.0.0.1',
    'REDIS_PASSWORD': 'null',
    'REDIS_PORT': '6379',
    'CACHE_STORE': 'redis',
    'SESSION_DRIVER': 'redis',
    'SESSION_DOMAIN': 'null',
    'SESSION_SECURE_COOKIE': 'true',
    'QUEUE_CONNECTION': 'redis',
    'MERCADOPAGO_MODO_TESTE': 'false',
}
desligar = {'YT_DLP_BINARY', 'FFMPEG_BINARY', 'VIDEO_DOWNLOAD_JS_RUNTIMES',
            'VIDEO_DOWNLOAD_YOUTUBE_POT_PROVIDER_HOME', 'VIDEO_DOWNLOAD_COOKIES_PATH'}

saida, vistos, trocados = [], set(), []
for linha in linhas:
    if '=' not in linha or linha.lstrip().startswith('#'):
        saida.append(linha)
        continue
    chave, valor = linha.split('=', 1)
    chave = chave.strip()
    if chave in desligar:
        saida.append('# (Hostinger, desligado na VPS) ' + chave + '=')
        continue
    if chave in fixos:
        valor = fixos[chave]
        vistos.add(chave)
    elif HOMOLOG in valor:
        valor = valor.replace(HOMOLOG, PRODUCAO)
        trocados.append(chave)
    saida.append(f'{chave}={valor}')

for chave, valor in fixos.items():
    if chave not in vistos:
        saida.append(f'{chave}={valor}')

os.umask(0o027)
with open(DESTINO, 'w', encoding='utf-8') as arquivo:
    arquivo.write('\n'.join(saida) + '\n')
os.chown(DESTINO, pwd.getpwnam('deploy').pw_uid, grp.getgrnam('www-data').gr_gid)
os.chmod(DESTINO, 0o640)

print(f'.env de produção criado em {DESTINO} ({len(saida)} linhas)')
print('Endereço trocado para kazakora.com em:', ', '.join(trocados) or 'nenhuma')
print('Ajustadas:', ', '.join(sorted(fixos)))
