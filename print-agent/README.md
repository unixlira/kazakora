# Kazakora Print Agent

Agente local que roda no mini PC Windows conectado à impressora de etiquetas.
Busca etiquetas de envio pendentes (Shopee, Mercado Livre, TikTok — e Amazon
quando integrada) na API da Kazakora e imprime automaticamente, sem
intervenção manual.

> Substitui o agente PowerShell simples usado antes (`kazakora-print-agent.ps1`,
> via Agendador de Tarefas) — trocado por uma fila persistente de verdade
> (Redis/BullMQ) com retry/backoff/dead letter queue reais, rodando como
> serviço do Windows via PM2.

## Arquitetura

Três peças, dois processos:

- **Poller** (`src/poller-process.js`) — a cada `POLL_INTERVAL_MS` (padrão
  5s), consulta `GET /api/print-agent/jobs`. Pra cada job novo (controle de
  idempotência via `SET NX EX` no Redis, chave por `print_job_id`, TTL de
  24h), enfileira no BullMQ.
- **Fila** (Redis + BullMQ) — persistente em disco (Redis com AOF), sobrevive
  a reinício do PC. Cada job: até 5 tentativas, backoff exponencial (2s, 4s,
  8s, 16s, 32s). Jobs com sucesso: mantém os últimos 100 pra auditoria. Jobs
  que esgotaram as tentativas ficam retidos como dead letter queue (nunca são
  descartados sozinhos).
- **Worker** (`src/worker-process.js`) — consome um job por vez
  (`concurrency: 1`, a impressora é um recurso sequencial): reivindica o job
  na API (`POST /jobs/{id}/claim`), baixa o PDF da etiqueta
  (`GET /jobs/{id}/label`), manda pra impressora do Windows, e reporta o
  resultado (`POST /jobs/{id}/complete`) — isso já dispara a timeline do
  pedido e a notificação de admin em caso de falha, do lado do Laravel.

Poller e Worker rodam como **dois processos PM2 separados** — se a impressora
travar (papel, offline, driver), o Worker pode reiniciar sozinho sem
interromper o Poller, que continua alimentando a fila normalmente.

## Por que não ESC/POS / node-thermal-printer

A etiqueta que a Shopee/ML/TikTok geram já vem pronta como **PDF** (a API do
Laravel devolve `Content-Type: application/pdf`) — não é um recibo de texto
formatado na hora. Por isso o agente usa `pdf-to-printer` (envia o PDF pro
spooler nativo do Windows) em vez de uma biblioteca ESC/POS. Isso também
simplifica USB vs. rede: uma vez que a impressora está instalada no Windows
(com o driver certo, seja por cabo ou por IP), ela aparece como um nome de
impressora comum — o código não precisa saber a diferença.

## Instalação no mini PC (Windows)

### 0. Se o agente PowerShell antigo estiver instalado, remova-o primeiro

Abra o "Agendador de Tarefas" do Windows e exclua a tarefa "Kazakora -
Impressão de Etiquetas" (ou o nome equivalente), pra evitar dois agentes
disputando o mesmo job ao mesmo tempo.

### 1. Node.js
Instale o Node.js LTS: https://nodejs.org

### 2. Redis (via Memurai)
O Redis oficial não tem build nativa mantida pra Windows. Use o
**[Memurai](https://www.memurai.com/get-memurai)** (compatível com o
protocolo Redis, roda nativamente como serviço do Windows, tem edição
gratuita/Developer suficiente pra esse volume). Alternativas — WSL2 ou
Docker Desktop — funcionam, mas adicionam uma camada extra num PC dedicado
só a isso; Memurai é o caminho mais direto.

Durante a instalação do Memurai, confirme que ele já sobe como **serviço do
Windows** (inicia sozinho no boot) e que a persistência (AOF) está ativa —
isso vem habilitado por padrão na maioria das edições.

### 3. Instalar a impressora no Windows
Instale a impressora normalmente em "Configurações > Impressoras e
scanners" (USB: plug-and-play com o driver do fabricante; rede: adicionar
por IP/porta 9100 com o driver certo). Depois, rode:

```
npm install
npm run printers
```

e copie o nome exato que aparecer pro `PRINTER_NAME` do `.env`.

### 4. Configurar o agente

```
cd print-agent
npm install
copy .env.example .env
```

Edite o `.env`:
- `PRINT_AGENT_TOKEN` — mesmo valor do `PRINT_AGENT_TOKEN` no `.env` do
  servidor Laravel (peça pra quem administra o servidor).
- `PRINTER_NAME` — nome copiado do passo 3.
- Demais valores já vêm com padrões razoáveis pro volume atual (10-30
  pedidos/dia, com folga pra crescer até ~1000/dia sem mudar nada).

### 5. Instalar como serviço do Windows (PM2)

```
npm install -g pm2 pm2-windows-startup
pm2-startup install
pm2 start ecosystem.config.js
pm2 save
```

Isso registra Poller e Worker pra iniciarem automaticamente no boot e
reiniciarem sozinhos em caso de crash.

## Operação do dia a dia

```
pm2 list                     # status dos dois processos
pm2 logs kazakora-print-worker   # acompanhar impressão em tempo real
pm2 restart kazakora-print-worker
pm2 stop all
pm2 start all
```

Logs também ficam gravados em `logs/` (rotação diária, 30 dias de retenção).

### Consultar a dead letter queue (jobs que falharam todas as tentativas)

```
npm run dlq
```

Depois de resolver a causa (reabastecer papel, religar a impressora):

```
node src/retry-job.js <bull_job_id>
```

(o `bull_job_id` aparece na saída do `npm run dlq`)

## Testando localmente antes de instalar como serviço

```
npm start
```

Roda Poller e Worker juntos num único processo no terminal (Ctrl+C pra
parar) — útil pra validar a configuração antes de registrar no PM2.

## Variáveis de ambiente (`.env`)

| Variável | Descrição | Padrão |
|---|---|---|
| `API_BASE_URL` | URL base do servidor Laravel | — |
| `PRINT_AGENT_TOKEN` | Token do agente (igual ao do servidor) | — |
| `AGENT_ID` | Identifica esta máquina no `claimed_by` | hostname da máquina |
| `PRINTER_NAME` | Nome exato da impressora no Windows | — |
| `POLL_INTERVAL_MS` | Intervalo de polling | 5000 |
| `REDIS_URL` | Conexão com o Redis/Memurai local | redis://127.0.0.1:6379 |
| `PRINT_MAX_ATTEMPTS` | Tentativas antes da dead letter queue | 5 |
| `PRINT_BACKOFF_BASE_MS` | Base do backoff exponencial | 2000 |
| `PRINT_KEEP_COMPLETED` | Jobs concluídos mantidos p/ auditoria | 100 |
| `DEDUPE_TTL_SECONDS` | TTL do controle de idempotência | 86400 (24h) |
| `TEMP_DIR` | Onde o PDF é gravado antes de imprimir | ./tmp |
| `LOG_DIR` / `LOG_LEVEL` | Configuração de log | ./logs / info |

## Limitações conhecidas / não testado

Este código **não foi executado contra uma impressora física real** — foi
escrito e revisado, mas o ambiente de desenvolvimento não tem um mini PC
Windows nem impressora conectada disponível. Antes de confiar nisso em
produção: valide que `npm run printers` mostra a impressora certa, gere uma
etiqueta de teste real via `print_jobs` (mesmo processo já usado uma vez
manualmente) e confirme que ela sai fisicamente impressa e corretamente
dimensionada (100x150mm) antes de remover o agente PowerShell antigo de vez.
