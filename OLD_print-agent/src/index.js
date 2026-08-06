// Ponto de entrada único, útil só pra rodar/testar tudo localmente com um
// só comando (`npm start`). Em produção no mini PC, prefira o
// ecosystem.config.js do PM2, que sobe Poller e Worker como dois processos
// separados de verdade (ver README) — assim um crash no Worker não
// interrompe o Poller, e vice-versa.
const logger = require('./logger');
const poller = require('./poller');
const worker = require('./worker');

logger.info('agent.starting_combined_mode');

const workerInstance = worker.start();
poller.start();

process.on('SIGTERM', async () => {
    logger.info('agent.shutting_down');
    await workerInstance.close();
    process.exit(0);
});

process.on('SIGINT', async () => {
    logger.info('agent.shutting_down');
    await workerInstance.close();
    process.exit(0);
});
