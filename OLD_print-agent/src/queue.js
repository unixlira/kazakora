const { Queue } = require('bullmq');
const connection = require('./redis');
const config = require('./config');

const QUEUE_NAME = 'shipping-labels';

const printQueue = new Queue(QUEUE_NAME, {
    connection,
    defaultJobOptions: {
        attempts: config.printMaxAttempts,
        backoff: {
            type: 'exponential',
            delay: config.printBackoffBaseMs,
        },
        // Sucesso: mantém só os últimos N pra auditoria (evita crescer pra
        // sempre). Falha: NUNCA remove sozinho — jobs que esgotaram as
        // tentativas ficam retidos aqui mesmo, funcionando como a dead
        // letter queue (ver dlq.js), até alguém revisar manualmente.
        removeOnComplete: { count: config.printKeepCompleted },
        removeOnFail: false,
    },
});

module.exports = { printQueue, QUEUE_NAME };
