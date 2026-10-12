// Agente da Amazon (2026-10-12): baixa os relatórios de pagamento da Seller
// Central no Chrome do agente (o mesmo do TikTok, porta 9222) e manda para a
// API da KazaKora, que grava a taxa real de cada pedido.
//
//   node amazon.mjs reconhecer   -> abre as telas, salva prints e a lista de botões/links (logs/)
//   node amazon.mjs diario       -> extratos (Flat File V2) + transações do mês
//   node amazon.mjs extratos     -> só os Flat File V2 das últimas liquidações
//   node amazon.mjs transacoes   -> só o relatório de transações do mês (por datas)
//
// Nunca digita senha nem resolve captcha/código: sessão caída = para e avisa.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PASTA = path.dirname(fileURLToPath(import.meta.url));
const cfg = JSON.parse(fs.readFileSync(path.join(PASTA, 'amazon.json'), 'utf8'));
const LOGS = path.join(PASTA, 'logs', 'amazon');
const TMP = path.join(PASTA, 'tmp', 'amazon');
for (const p of [LOGS, TMP]) fs.mkdirSync(p, { recursive: true });

const agora = () => new Date().toLocaleString('sv-SE', { timeZone: 'America/Sao_Paulo' }).replace(' ', 'T');
const log = (...m) => {
  const linha = `[${agora()}] ${m.join(' ')}`;
  console.log(linha);
  fs.appendFileSync(path.join(LOGS, 'amazon.log'), linha + '\n');
};
const hoje = () => new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Sao_Paulo' }); // AAAA-MM-DD

// --- Trava: uma execução por vez ------------------------------------------
const TRAVA = path.join(PASTA, 'amazon.lock');
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
  const r = corpo.arquivos?.[0] || {};
  if (r.erro_importacao) throw new Error(`${nome} chegou mas não importou: ${r.erro_importacao}`);
  const i = r.importado;
  log(`enviado ${nome}${r.duplicado ? ' (igual ao último, ignorado)' : ''}${i ? ` — ${i.linhas} linhas, ${i.pedidos} pedidos, ${i.taxas_reais} com taxa real` : ''}`);
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
  await pagina.waitForTimeout(5000);
  const atual = pagina.url();
  const texto = (await pagina.locator('body').innerText().catch(() => '')).slice(0, 4000);
  if (/\/ap\/signin|\/ap\/mfa|\/ap\/cvf/i.test(atual) || /Fazer login|Sign-In|Verificação em duas etapas|Two-Step Verification/i.test(texto)) {
    throw new Error('Sessão da Amazon caiu (pede login ou código) — faça login de novo no Chrome do agente');
  }
}

async function registrarTela(pagina, nome) {
  await pagina.screenshot({ path: path.join(LOGS, `${nome}.png`), fullPage: true }).catch(() => {});
  fs.writeFileSync(path.join(LOGS, `${nome}.html`), await pagina.content().catch(() => ''));
}

async function baixar(pagina, alvo, nome) {
  const [download] = await Promise.all([pagina.waitForEvent('download', { timeout: 120000 }), alvo.click()]);
  const extensao = path.extname(download.suggestedFilename()) || '.txt';
  const destino = path.join(TMP, `${nome}${extensao}`);
  await download.saveAs(destino);
  return { destino, nome: path.basename(destino) };
}

// --- Jobs --------------------------------------------------------------------
// Pagamentos › Todos os extratos: cada liquidação fechada tem o "Flat File V2"
// (TSV com cada componente: venda, frete, promoção, comissão, tarifas). A API
// ignora arquivo repetido, então baixar as últimas de novo não duplica.
async function extratos(pagina) {
  await garantirSessao(pagina, cfg.urls.extratos);
  const links = pagina.locator('a, button, [role=button], kat-button, kat-link').filter({ hasText: new RegExp(cfg.textos.flatFileV2, 'i') });
  const total = await links.count();
  if (total === 0) {
    await registrarTela(pagina, `sem-v2-${Date.now()}`);
    throw new Error('não achei o link do Flat File V2 em Todos os extratos (tela mudou?)');
  }
  const quantos = Math.min(total, cfg.maxExtratos || 4);
  log(`extratos: ${total} links V2, baixando ${quantos}`);
  for (let i = 0; i < quantos; i++) {
    const { destino, nome } = await baixar(pagina, links.nth(i), `amazon_liquidacao_${hoje()}_${i + 1}`);
    await enviar(destino, nome);
    fs.renameSync(destino, path.join(LOGS, nome));
  }
}

// Pagamentos › Repositório de relatórios: transações do mês por datas (pega
// o que ainda não fechou em liquidação). Fluxo mapeado só pelos textos do
// config; se a tela não bater, registra print e segue.
async function transacoes(pagina) {
  await garantirSessao(pagina, cfg.urls.repositorio);
  const t = cfg.textos;
  await pagina.getByText(t.tipoTransacao, { exact: true }).first().click().catch(() => {});
  await pagina.getByText(t.periodoPersonalizado, { exact: true }).first().click();
  const [ano, mes, dia] = hoje().split('-');
  const datas = pagina.locator('input[type=date], kat-date-picker input, input[placeholder*="/"]');
  if ((await datas.count()) < 2) throw new Error('não achei os campos de data do relatório por datas');
  await datas.nth(0).fill(`01/${mes}/${ano}`);
  await datas.nth(1).fill(`${dia}/${mes}/${ano}`);
  await pagina.getByRole('button', { name: new RegExp(t.solicitar, 'i') }).first().click();
  log(`transações: pedido 01/${mes}/${ano} a ${dia}/${mes}/${ano}`);

  // O relatório novo entra no topo da lista; espera o "Baixar CSV" acender (até 10 min).
  const limite = Date.now() + 10 * 60 * 1000;
  while (Date.now() < limite) {
    await pagina.waitForTimeout(20000);
    await pagina.reload({ waitUntil: 'domcontentloaded' });
    await pagina.waitForTimeout(4000);
    const botao = pagina.getByText(t.baixarCsv, { exact: false }).first();
    if (await botao.isVisible().catch(() => false)) {
      const { destino, nome } = await baixar(pagina, botao, `amazon_transacoes_${ano}-${mes}-01_${hoje()}`);
      await enviar(destino, nome);
      fs.renameSync(destino, path.join(LOGS, nome));
      return;
    }
  }
  throw new Error('o relatório de transações não ficou pronto em 10 minutos');
}

async function reconhecer(pagina) {
  for (const [chave, url] of Object.entries(cfg.urls)) {
    await garantirSessao(pagina, url).catch(e => log(`${chave}: ${e.message}`));
    await registrarTela(pagina, `reconhecer-${chave}`);
    const itens = await pagina.locator('a, button, [role=button], [role=tab], kat-button, kat-link, select, input').evaluateAll(els =>
      els.map(e => `${e.tagName.toLowerCase()}\t${(e.innerText || e.getAttribute('label') || e.getAttribute('aria-label') || e.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ')}\t${e.getAttribute('href') || ''}`)).catch(() => []);
    fs.writeFileSync(path.join(LOGS, `reconhecer-${chave}.txt`), itens.filter(l => l.split('\t')[1]).join('\n'));
    log(`reconhecer ${chave}: ${itens.length} itens salvos`);
  }
}

// --- Principal -------------------------------------------------------------
const tarefa = process.argv[2] || 'diario';
let sessao;
const falhas = [];
try {
  sessao = await abrir();
  log(`início: ${tarefa}`);
  if (tarefa === 'reconhecer') await reconhecer(sessao.pagina);
  // Um job falhar não impede o outro: o V2 é o que vale para a taxa real.
  for (const [nome, job] of [['extratos', extratos], ['transacoes', transacoes]]) {
    if (tarefa !== nome && tarefa !== 'diario') continue;
    try {
      await job(sessao.pagina);
    } catch (erro) {
      falhas.push(`${nome}: ${erro.message}`);
      log(`FALHA ${nome}: ${erro.message}`);
      await registrarTela(sessao.pagina, `falha-${nome}-${Date.now()}`);
      if (/Sessão da Amazon caiu/.test(erro.message)) break;
    }
  }
  if (falhas.length) throw new Error(falhas.join(' | '));
  log(`fim: ${tarefa} ok`);
} catch (erro) {
  if (!falhas.length) log(`FALHA ${tarefa}: ${erro.message}`);
  await avisarFalha(`Agente Amazon (${tarefa}): ${erro.message}`.slice(0, 500));
  process.exitCode = 1;
} finally {
  await sessao?.pagina?.close().catch(() => {});
  // Só fecha a conexão: o Chrome logado continua aberto.
  await sessao?.navegador?.close().catch(() => {});
  soltar();
}
