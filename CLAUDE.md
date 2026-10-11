# Kazakora: regras pra quem mexe no código

Dois agentes trabalham neste repositório, em dias diferentes ou em
paralelo:

- **Claude Code**: sessão do Lira no computador dele (WSL).
- **Naia** (Hermes, na VPS): o Lira fala com ela pelo Telegram quando está
  fora.

Os dois seguem as MESMAS regras abaixo. Elas existem porque já se perdeu
trabalho real: em 2026-08-30 e de novo em 2026-10-08, mudanças feitas
direto no servidor foram apagadas pelo deploy.

## Como o deploy funciona

`git push` no ramo `homolog` dispara o GitHub Actions, que sincroniza o
repositório inteiro pro servidor com `rsync --delete` e roda as migrations.
**Tudo o que está no servidor e não está no git é apagado ou sobrescrito.**
Push em qualquer outro ramo não publica nada.

**Produção (desde 2026-10-10):** push no `main` publica a loja
`kazakora.com` na VPS `187.127.60.51`, pela pipeline
`.github/workflows/deploy-producao.yml` (testes → pasta nova em
`releases/` → troca do link `current`, volta sozinha se a loja não
responder). Fluxo: ramo → `homolog` → validar → `main`. Detalhes em
`deploy/producao/LEIA-ME.md`.

## Regras

1. **Nunca editar código direto no servidor.** Nem pra "testar rapidinho".
   Toda mudança passa pelo git. O `.env` do servidor é a única exceção (não
   fica no git), e mudança nele tem que ser avisada ao Lira.
2. **`git pull origin homolog` antes de começar qualquer tarefa.**
3. **Cada tarefa num ramo próprio**: `feature/<assunto>` ou `fix/<assunto>`,
   saindo do `homolog` atualizado. Nunca dois agentes no mesmo ramo.
4. **Commits pequenos, mensagem em português** dizendo o que mudou e por
   quê. Autor sempre `unixlira <korashopecom@gmail.com>`, sem linha de
   co-autoria de IA.
5. **Rodar os testes antes de juntar** (`php artisan test`). Teste que
   falha por falta da extensão GD no ambiente local é conhecido; qualquer
   outra falha tem que ser resolvida.
6. **Publicar só com OK do Lira.** Juntar no `homolog` e dar push só depois
   que ele aprovar. Antes do push, conferir que o servidor está igual ao
   `origin/homolog` (abaixo) e, se não estiver, parar e avisar.
7. **Avisar o Lira ao terminar**: ramo, commits, arquivos alterados,
   variáveis novas no `.env`, como testar e como reverter.

## Conferir o servidor antes de publicar

Comparar o servidor com o que está publicado (`origin/homolog`), não com a
cópia local, numa etapa SEPARADA do push, e ler a saída:

```
git fetch origin
git worktree add /tmp/kazakora-publicado origin/homolog
rsync -rcn --out-format='%n' \
  --exclude=.git --exclude=.github --exclude=.env --exclude=/storage \
  --exclude=node_modules --exclude=tests --exclude=/vendor \
  --exclude=/public/build --exclude=/bootstrap/cache --exclude=/public/storage \
  -e "ssh -p 65002" u902517809@147.93.37.237:domains/devlira.com.br/public_html/kazakora/ \
  /tmp/kazakora-publicado/
git worktree remove --force /tmp/kazakora-publicado
```

Saída vazia = pode publicar. Qualquer arquivo listado = alguém mexeu no
servidor por fora: trazer pro git antes, nunca publicar por cima.

## Referências rápidas

- Cookies, sessão e LGPD da loja: `docs/privacidade-e-cookies.md`. Qualquer
  coleta nova de dado de visitante (pixel, analytics) segue as regras de lá.
- Servidor: Hostinger compartilhada, PHP em `/opt/alt/php83/usr/bin/php`,
  app em `~/domains/devlira.com.br/public_html/kazakora`.
- Backups manuais: `~/deploy-backups` no servidor.
- WhatsApp/Manuela: a IA é o Gemini (`GEMINI_API_KEY`), código em
  `app/Modules/WhatsApp`. O caminho pelo Hermes existe mas está desligado.
