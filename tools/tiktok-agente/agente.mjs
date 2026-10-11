// Agente do TikTok (2026-10-11): baixa os relatórios financeiros da Central
// do Vendedor no Chrome já logado e manda para a API da KazaKora.
//
//   node agente.mjs reconhecer     -> abre as telas, salva prints e a lista de botões (logs/)
//   node agente.mjs diario         -> demonstrativo (D-7..D-1) + pedidos de hoje
//   node agente.mjs demonstrativo  -> só o demonstrativo
//   node agente.mjs pedidos        -> só os pedidos de hoje
//
// Nunca digita senha nem resolve captcha: sessão caída = para e avisa.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PASTA = path.dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(fs.readFileSync(path.join(PASTA, 'config.json'), 'utf8'));
const LOGS = path.join(PASTA, 'logs');
const TMP = path.join(PASTA, 'tmp');
for (const p of [LOGS, TMP]) fs.mkdirSync(p, { recursive: true });

const agora = () => new Date().toLocaleString('sv-SE', { timeZone: 'America/Sao_Paulo' }).replace(' ', 'T');
const log = (...m) => {
  const linha = `[${agora()}] ${m.join(' ')}`;
  console.log(linha);
  fs.appendFileSync(path.join(LOGS, 'agente.log'), linha + '\n');
};
const dia = (deslocamento = 0) => {
  const d = new Date(Date.now() + deslocamento * 86400000);
  return d.toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' }); // AAAA-MM-DD
};

// --- Trava: uma execução por vez ------------------------------------------
const TRAVA = path.join(PASTA, 'agente.lock');
if (fs.existsSync(TRAVA) && Date.now() - fs.statSync(TRAVA).mtimeMs < 60 * 60 * 1000) {
  log('outra execução em andamento — saindo');
  process.exit(0);
}
fs.writeFileSync(TRAVA, String(process.pid));
const soltar = () => { try { fs.unlinkSync(TRAVA); } catch {} };

// --- API ---------------------------------------------------------------------
async function enviar(arquivo, nome) {
  const form = new FormData();
  form.append('file', new Blob([fs.readFileSync(arquivo)]), nome);
  const res = await fetch(cfg.apiUrl, { method: 'POST', headers: { Authorization: `Bearer ${cfg.apiToken}` }, body: form });
  const corpo = await res.json().catch(() => ({}));
  if (!res.ok || corpo.ok === false) throw new Error(`API recusou ${nome}: HTTP ${res.status} ${corpo.erro || ''}`);
  const dup = corpo.arquivos?.[0]?.duplicado;
  log(`enviado ${nome}${dup ? ' (igual ao último, ignorado)' : ''}`);
}

async function avisarFalha(mensagem) {
  try {
    await fetch(cfg.apiUrl.replace(/import$/, 'status'), {
      method: 'POST',
      headers: { Authorization: `Bearer ${cfg.apiToken}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ ok: false, mensagem }),
    });
  } catch {}
}

// --- Navegador ------------------------------------------------------------
async function abrir() {
  const navegador = await chromium.connectOverCDP(cfg.cdpUrl || 'http://127.0.0.1:9222');
  const contexto = navegador.contexts()[0];
  const pagina = await contexto.newPage();
  pagina.setDefaultTimeout(30000);
  return { navegador, pagina };
}

async function garantirSessao(pagina, url) {
  await pagina.goto(url, { waitUntil: 'domcontentloaded' });
  await pagina.waitForTimeout(4000);
  const atual = pagina.url();
  const texto = (await pagina.locator('body').innerText().catch(() => '')).slice(0, 4000);
  if (/login|signin|passport/i.test(atual) || /Sem permissão|Faça login|Entrar na conta/i.test(texto)) {
    throw new Error('Sessão do TikTok caiu ou sem permissão — faça login de novo no Chrome do agente');
  }
}

async function registrarTela(pagina, nome) {
  await pagina.screenshot({ path: path.join(LOGS, `${nome}.png`), fullPage: true }).catch(() => {});
  fs.writeFileSync(path.join(LOGS, `${nome}.html`), await pagina.content().catch(() => ''));
}

// Clica em "Exportar" e pega o arquivo — na hora ou pelo "Exportar histórico".
async function exportar(pagina, prefixo) {
  const t = cfg.textos;
  const botao = pagina.getByRole('button', { name: t.exportar, exact: true }).first();
  let download = null;
  try {
    [download] = await Promise.all([
      pagina.waitForEvent('download', { timeout: 60000 }),
      botao.click(),
    ]);
  } catch {
    // Exportação assíncrona: o arquivo aparece no histórico depois de gerado.
    log('download não veio direto — tentando pelo histórico');
    await pagina.waitForTimeout(20000);
    await pagina.getByText(t.historico, { exact: false }).first().click();
    await pagina.waitForTimeout(3000);
    [download] = await Promise.all([
      pagina.waitForEvent('download', { timeout: 120000 }),
      pagina.getByRole('button', { name: t.baixar }).first().click(),
    ]);
  }
  const sugerido = download.suggestedFilename();
  const extensao = path.extname(sugerido) || '.xlsx';
  const nome = `${prefixo}${extensao}`;
  const destino = path.join(TMP, nome);
  await download.saveAs(destino);
  return { destino, nome };
}

// --- Jobs --------------------------------------------------------------------
async function demonstrativo(pagina) {
  await garantirSessao(pagina, cfg.urls.demonstrativos);
  // "Exibir por pedidos": uma linha por pedido no arquivo.
  await pagina.getByText(cfg.textos.porPedidos, { exact: false }).first().click().catch(() => log('aviso: não achei "Exibir por pedidos"'));
  await pagina.waitForTimeout(2000);
  const { destino, nome } = await exportar(pagina, `tiktok_demonstrativo_${dia(-7)}_a_${dia(-1)}_${Date.now()}`);
  await enviar(destino, nome);
}

async function pedidos(pagina) {
  await garantirSessao(pagina, cfg.urls.pedidos);
  const { destino, nome } = await exportar(pagina, `tiktok_pedidos_${dia(0)}_${Date.now()}`);
  await enviar(destino, nome);
}

async function reconhecer(pagina) {
  for (const [chave, url] of Object.entries(cfg.urls)) {
    await garantirSessao(pagina, url).catch(e => log(`${chave}: ${e.message}`));
    await registrarTela(pagina, `reconhecer-${chave}`);
    const botoes = await pagina.locator('button, [role=button], [role=tab]').allInnerTexts().catch(() => []);
    fs.writeFileSync(path.join(LOGS, `reconhecer-${chave}.txt`), botoes.map(b => b.trim()).filter(Boolean).join('\n'));
    log(`reconhecer ${chave}: ${botoes.length} botões salvos`);
  }
}

// --- Principal -------------------------------------------------------------
const tarefa = process.argv[2] || 'diario';
let sessao;
try {
  sessao = await abrir();
  log(`início: ${tarefa}`);
  if (tarefa === 'reconhecer') await reconhecer(sessao.pagina);
  if (tarefa === 'demonstrativo' || tarefa === 'diario') await demonstrativo(sessao.pagina);
  if (tarefa === 'pedidos' || tarefa === 'diario') await pedidos(sessao.pagina);
  log(`fim: ${tarefa} ok`);
} catch (erro) {
  log(`FALHA ${tarefa}: ${erro.message}`);
  if (sessao?.pagina) await registrarTela(sessao.pagina, `falha-${tarefa}-${Date.now()}`);
  await avisarFalha(`Agente TikTok (${tarefa}): ${erro.message}`);
  process.exitCode = 1;
} finally {
  await sessao?.pagina?.close().catch(() => {});
  // Só fecha a conexão: o Chrome logado continua aberto.
  await sessao?.navegador?.close().catch(() => {});
  soltar();
}
