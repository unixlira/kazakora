// Pedidos do TikTok Shop ainda não liquidados, com os valores que o TikTok
// prevê (Finanças › Em espera), para a conta de lucro da KazaKora (pedido do
// Lira 04/11/2026). Até o extrato chegar, o KazaKora usava a própria
// estimativa: em 04/11, R$ 4.002 contra R$ 6.532 que o TikTok vai pagar.
//
//   node em-espera.mjs          -> lê a tela e manda para POST /api/tiktok/em-espera
//   node em-espera.mjs --seco   -> só lê e salva logs/em-espera.json (não envia)
//
// Abre uma aba própria no Chrome do agente (cdp-aba.mjs da pasta C:\tiktok-agente
// da Cora Box, como o agente.mjs de lá),
// pega a chamada que a tela faz (onhold_orders) e repete com a mesma URL,
// voltando no tempo de 50 em 50 pedidos. Fecha a aba no fim. Nunca digita
// senha nem resolve captcha. Mesma pasta e config.json do agente.mjs; roda
// depois da coleta do TikTok no financeiro-hora.mjs.
import { Aba } from './cdp-aba.mjs';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PASTA = path.dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(fs.readFileSync(path.join(PASTA, 'config.json'), 'utf8'));
const LOGS = path.join(PASTA, 'logs');
fs.mkdirSync(LOGS, { recursive: true });
const SECO = process.argv.includes('--seco');
const URL_TELA = 'https://seller-br.tiktok.com/finance/bills?subTab=bills&tab=statements';
const URL_API = cfg.apiUrl.replace(/reports\/import$/, 'em-espera');

const agora = () => new Date().toLocaleString('sv-SE', { timeZone: 'America/Sao_Paulo' }).replace(' ', 'T');
const log = (...m) => {
  const linha = `[${agora()}] em-espera: ${m.join(' ')}`;
  console.log(linha);
  fs.appendFileSync(path.join(LOGS, 'agente.log'), linha + '\n');
};
const espera = ms => new Promise(r => setTimeout(r, ms));

async function avisarFalha(mensagem) {
  try {
    await fetch(cfg.apiUrl.replace(/import$/, 'status'), {
      method: 'POST',
      headers: { Authorization: `Bearer ${cfg.apiToken}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ok: false, mensagem }),
    });
  } catch {}
}

let aba;
try {
  aba = await Aba.abrir(cfg.cdpUrl || 'http://127.0.0.1:9222');
  await aba.minimizar();
  await aba.pagina.chamar('Network.enable');
  let chamada = null;
  aba.pagina.ouvintes.add(d => {
    if (d.method === 'Network.requestWillBeSent' && d.params.request.url.includes('/statement/view/onhold_orders')) chamada = d.params.request;
  });

  await aba.ir(URL_TELA);
  await espera(5000);
  if (/login|signin|passport/i.test(await aba.url())) throw new Error('Sessão do TikTok caiu: faça login de novo no Chrome do agente');
  await aba.clicar(`return porTexto(document, 'Em espera').find(visivel);`, 'a aba "Em espera"');
  for (let i = 0; !chamada && i < 30; i++) await espera(1000);
  if (!chamada) throw new Error('a tela Em espera não chamou a lista de pedidos');

  // Os IDs vêm como número de 18 dígitos: o JSON.parse do navegador perde os
  // últimos (586956008411923903 vira ...800). Vira texto antes de ler.
  const pedidos = await aba.avaliar(`
    const url = ${JSON.stringify(chamada.url)};
    const base = JSON.parse(${JSON.stringify(chamada.postData || '{}')});
    const todos = new Map();
    let ate = base.place_time_upper;
    for (let p = 0; p < 40; p++) {
      const t = await (await fetch(url, { method: 'POST', credentials: 'include', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ ...base, place_time_upper: ate }) })).text();
      const j = JSON.parse(t.replace(/"(\\w+)":(\\d{15,})/g, '"$1":"$2"'));
      if (j.code !== 0) throw new Error('lista Em espera recusada: ' + (j.message || j.code));
      const lote = j.data?.order_records || [];
      for (const o of lote) { delete o.sku_records; todos.set(o.expression_order_id, o); }
      if (lote.length < (base.size || 50)) break;
      ate = Math.min(...lote.map(o => Number(o.order_create_time)));
    }
    return [...todos.values()];`, 120000);

  fs.writeFileSync(path.join(LOGS, 'em-espera.json'), JSON.stringify({ lidoEm: agora(), pedidos }));
  const total = pedidos.reduce((s, o) => s + Number(o.total_onhold_net_amount?.amount || 0), 0);
  if (SECO) {
    log(`seco: ${pedidos.length} pedidos, R$ ${total.toFixed(2)} a receber (logs/em-espera.json)`);
  } else {
    const res = await fetch(URL_API, {
      method: 'POST',
      headers: { Authorization: `Bearer ${cfg.apiToken}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ pedidos }),
    });
    const corpo = await res.json().catch(() => ({}));
    if (!res.ok || corpo.ok === false) throw new Error(`API recusou: HTTP ${res.status} ${corpo.erro || ''}`);
    log(`ok: ${pedidos.length} pedidos, R$ ${total.toFixed(2)} a receber; ${corpo.gravados} gravados, ${corpo.ja_liquidados} já no extrato, ${(corpo.sem_pedido || []).length} sem pedido no KazaKora`);
  }
} catch (erro) {
  log(`FALHA: ${erro.message}`);
  if (!SECO) await avisarFalha(`Agente TikTok (em espera): ${erro.message}`);
  process.exitCode = 1;
} finally {
  await aba?.fechar().catch(() => {});
}
process.exit(process.exitCode || 0);
