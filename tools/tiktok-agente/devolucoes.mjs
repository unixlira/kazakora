// Devoluções do TikTok Shop para o admin da KazaKora (pedido do Lira 03/11/2026).
//
//   node devolucoes.mjs
//
// Abre uma aba no Chrome do agente (já logado no seller-br), carrega
// Pedidos › Devoluções, pega a lista pela mesma chamada que a tela usa
// (api/v1/reverse/component/orders/list, aba "Tudo") e manda os cartões crus
// para POST /api/tiktok/devolucoes. A leitura dos cartões fica no servidor.
// Fecha a aba no fim. Nunca digita senha nem resolve captcha.
//
// Agendar (a cada 30 min, mesma pasta e config.json do agente.mjs):
//   schtasks /create /tn "Agente TikTok - Devolucoes" /sc minute /mo 30 /tr "wscript.exe \"C:\tiktok-agente\oculto.vbs\" \"C:\Program Files\nodejs\node.exe\" \"C:\tiktok-agente\devolucoes.mjs\""
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PASTA = path.dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(fs.readFileSync(path.join(PASTA, 'config.json'), 'utf8'));
const LOGS = path.join(PASTA, 'logs');
fs.mkdirSync(LOGS, { recursive: true });

const URL_TELA = 'https://seller-br.tiktok.com/order/return?order_sort_comp=OrderSort_UPADTE_TIME_DESC&tab=100';
const URL_API = cfg.apiUrl.replace(/reports\/import$/, 'devolucoes');
const POR_PAGINA = 50;
const MAXIMO = 500;

const agora = () => new Date().toLocaleString('sv-SE', { timeZone: 'America/Sao_Paulo' }).replace(' ', 'T');
const log = (...m) => {
  const linha = `[${agora()}] devolucoes: ${m.join(' ')}`;
  console.log(linha);
  fs.appendFileSync(path.join(LOGS, 'agente.log'), linha + '\n');
};

const TRAVA = path.join(PASTA, 'devolucoes.lock');
if (fs.existsSync(TRAVA) && Date.now() - fs.statSync(TRAVA).mtimeMs < 20 * 60 * 1000) {
  log('outra execução em andamento, saindo');
  process.exit(0);
}
fs.writeFileSync(TRAVA, String(process.pid));

async function avisarFalha(mensagem) {
  try {
    await fetch(cfg.apiUrl.replace(/import$/, 'status'), {
      method: 'POST',
      headers: { Authorization: `Bearer ${cfg.apiToken}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ok: false, mensagem }),
    });
  } catch {}
}

let navegador;
let pagina;
try {
  navegador = await chromium.connectOverCDP(cfg.cdpUrl || 'http://127.0.0.1:9222');
  pagina = await navegador.contexts()[0].newPage();
  pagina.setDefaultTimeout(30000);

  // A URL da lista leva os parâmetros da sessão (seller_id, fp...): pega a
  // que a própria tela chama ao abrir.
  const [pedido] = await Promise.all([
    pagina.waitForRequest(r => r.url().includes('/api/v1/reverse/component/orders/list'), { timeout: 45000 }),
    pagina.goto(URL_TELA, { waitUntil: 'domcontentloaded' }),
  ]).catch(async erro => {
    const atual = pagina.url();
    if (/login|signin|passport/i.test(atual)) throw new Error('Sessão do TikTok caiu: faça login de novo no Chrome do agente');
    throw erro;
  });
  const urlLista = pedido.url();

  const cartoes = [];
  for (let offset = 0; offset < MAXIMO; offset += POR_PAGINA) {
    const resposta = await pagina.evaluate(async ({ url, offset, count }) => {
      const r = await fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          search_condition: { tab: { str_value_list: ['100'] }, order_sort_comp: { str_value_list: ['OrderSort_UPADTE_TIME_DESC'] } },
          pagination_type: 0, offset, count, component_version: 'hit_ui_opt',
        }),
      });
      return r.json();
    }, { url: urlLista, offset, count: POR_PAGINA });

    if (resposta?.code !== 0) throw new Error(`lista de devoluções recusada: ${resposta?.message || 'sem resposta'}`);
    const pagina_ = resposta.data?.cards || [];
    cartoes.push(...pagina_);
    if (pagina_.length < POR_PAGINA || cartoes.length >= (resposta.data?.total_count || 0)) break;
  }

  const res = await fetch(URL_API, {
    method: 'POST',
    headers: { Authorization: `Bearer ${cfg.apiToken}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ cartoes }),
  });
  const corpo = await res.json().catch(() => ({}));
  if (!res.ok || corpo.ok === false) throw new Error(`API recusou: HTTP ${res.status} ${corpo.erro || ''}`);
  log(`ok: ${cartoes.length} lidas, ${corpo.gravados} gravadas`);
} catch (erro) {
  log(`FALHA: ${erro.message}`);
  await avisarFalha(`Agente TikTok (devoluções): ${erro.message}`);
  process.exitCode = 1;
} finally {
  await pagina?.close().catch(() => {});
  // Só fecha a conexão: o Chrome logado continua aberto.
  await navegador?.close().catch(() => {});
  try { fs.unlinkSync(TRAVA); } catch {}
}
