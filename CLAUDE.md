# ⚠️ Antes de mexer neste projeto: dê `git pull` primeiro

Dois agentes editam este mesmo código, dos dois lados:

- **Claude Code** (sessão do usuário, roda local/WSL) — deploya via
  `git push` pra `homolog` → GitHub Actions builda e sincroniza tudo pro
  servidor via rsync `--delete`: isso SOBRESCREVE e APAGA qualquer
  arquivo do servidor que não esteja no git.
- **Outro agente** (recebido via Telegram pelo usuário) — edita os
  arquivos DIRETO no servidor, por SSH, sem passar pelo git.

**Isso já causou perda de trabalho real (2026-08-30):** funcionalidades
inteiras construídas direto em produção (listagem de vendas agendadas do
Mercado Livre, integração real da Shopee, fotos de anúncio) nunca tinham
sido commitadas — um `git push` daqui reverteria/apagaria tudo isso sem
aviso nenhum, só por rsync espelhar o que está (ou não está) no git.

## Regra, pros dois lados, sempre

1. **`git pull origin homolog` antes de editar qualquer coisa** — pra
   saber o que o outro lado já fez desde a última vez.
2. **Commit + `git push` do que você mudar assim que puder.** Se uma
   mudança fica só no servidor (ou só local, sem push), o outro lado não
   sabe que ela existe — e o próximo push dele pode apagar sem querer.
3. Se o código real do servidor estiver diferente do que `git log`
   mostra, **não empurre por cima sem investigar antes** — alguém mudou
   direto no servidor por fora do git. Puxe o arquivo real de lá,
   reconcilie com o que você ia mudar, só depois commite e dê push.
