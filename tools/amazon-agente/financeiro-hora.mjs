// Coleta financeira de hora em hora na Cora Box (2026-10-16): TikTok e Amazon
// pelo Chrome do agente (porta 9222), um depois do outro, para não disputarem
// o navegador. Cada coleta é o agente de sempre (agente.mjs / amazon.mjs), que
// baixa o relatório e manda para a API da KazaKora.
//
//   node financeiro-hora.mjs            -> roda as coletas ativas em financeiro.json
//   node financeiro-hora.mjs tiktok     -> só uma coleta (teste)
//
// Não avisa ninguém: grava logs\financeiro\estado.json e o vigia do Homer
// (ferramentas/financeiro-vigia.sh, no PC do Lira) lê esse arquivo por SSH e
// aciona a Manuela no WhatsApp quando a coleta falha ou para de rodar.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PASTA = path.dirname(fileURLToPath(import.meta.url));
const LOGS = path.join(PASTA, 'logs', 'financeiro');
fs.mkdirSync(LOGS, { recursive: true });
const ESTADO = path.join(LOGS, 'estado.json');

const COLETAS = {
  tiktok: { script: 'agente.mjs', args: ['diario'] },
  amazon: { script: 'amazon.mjs', args: ['diario'] },
};
const cfgArq = path.join(PASTA, 'financeiro.json');
const cfg = fs.existsSync(cfgArq) ? JSON.parse(fs.readFileSync(cfgArq, 'utf8')) : { ativas: ['tiktok', 'amazon'] };

const agora = () => new Date().toLocaleString('sv-SE', { timeZone: 'America/Sao_Paulo' }).replace(' ', 'T');
const log = (...m) => fs.appendFileSync(path.join(LOGS, 'financeiro.log'), `[${agora()}] ${m.join(' ')}\n`);

// Login caído pede o Lira; Chrome ocupado ou execução sobreposta só adia.
function classificar(saida, codigo) {
  if (/outra execução em andamento/i.test(saida)) return 'pulado';
  if (codigo === 0) return 'ok';
  // Rota da API ainda não publicada na produção (Amazon até o deploy): a
  // coleta leu a tela; como pega 30 dias, a primeira hora depois do deploy recupera tudo.
  if (/API recusou .*HTTP 404/.test(saida)) return 'sem_api';
  if (/Sessão d[oa] .* caiu|faça login|sem permissão|\/ap\/signin|Two-Step|Verificação em duas etapas/i.test(saida)) return 'login';
  if (/ocupado por outro programa|connectOverCDP|ECONNREFUSED/i.test(saida)) return 'chrome';
  return 'erro';
}

const estado = fs.existsSync(ESTADO) ? JSON.parse(fs.readFileSync(ESTADO, 'utf8')) : { coletas: {} };
const pedidas = process.argv[2] ? [process.argv[2]] : (cfg.ativas || []);
log(`início: ${pedidas.join(', ') || 'nenhuma coleta ativa'}`);

for (const nome of pedidas) {
  const coleta = COLETAS[nome];
  if (!coleta) { log(`coleta desconhecida: ${nome}`); continue; }
  const r = spawnSync(process.execPath, [path.join(PASTA, coleta.script), ...coleta.args], {
    cwd: PASTA, encoding: 'utf8', timeout: 25 * 60 * 1000, windowsHide: true,
  });
  const saida = `${r.stdout || ''}\n${r.stderr || ''}${r.error ? `\n${r.error.message}` : ''}`;
  const codigo = r.status ?? 1;
  const status = classificar(saida, codigo);
  const falha = saida.split(/\r?\n/).reverse().find(l => /FALHA/.test(l)) || saida.trim().split(/\r?\n/).pop() || '';
  const anterior = estado.coletas[nome] || {};
  estado.coletas[nome] = {
    ...anterior,
    ultimaExecucao: agora(),
    status,
    mensagem: status === 'ok' ? '' : falha.replace(/^\[[^\]]+\]\s*/, '').slice(0, 300),
    ...(status === 'ok' ? { ultimoOk: agora(), falhasSeguidas: 0 } : {}),
    ...(['login', 'chrome', 'erro'].includes(status) ? { falhasSeguidas: (anterior.falhasSeguidas || 0) + 1 } : {}),
  };
  log(`${nome}: ${status} (código ${codigo})${status === 'ok' ? '' : ` ${estado.coletas[nome].mensagem}`}`);
  // Login caído na Amazon não impede o TikTok, e vice-versa: segue para a próxima.
}

estado.atualizado = agora();
fs.writeFileSync(ESTADO, JSON.stringify(estado, null, 2));
log('fim');
