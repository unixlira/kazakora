// Processo dedicado só ao Worker — rodar via PM2 como app separado do
// Poller (ver ecosystem.config.js). Se travar/crashar (ex: driver da
// impressora com problema), o PM2 reinicia sozinho e os jobs continuam
// esperando na fila do Redis, sem perda.
const logger = require('./logger');
const worker = require('./worker');

const instance = worker.start();

process.on('SIGTERM', async () => {
    logger.info('worker-process.shutting_down');
    await instance.close();
    process.exit(0);
});
