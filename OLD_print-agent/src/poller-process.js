// Processo dedicado só ao Poller — rodar via PM2 como app separado do
// Worker (ver ecosystem.config.js). Continua batendo na API mesmo que o
// Worker/impressora estejam com problema; os jobs só se acumulam na fila.
const logger = require('./logger');
const poller = require('./poller');

poller.start();

process.on('SIGTERM', () => {
    logger.info('poller-process.shutting_down');
    process.exit(0);
});
