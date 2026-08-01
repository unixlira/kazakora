const config = require('./config');
const logger = require('./logger');
const redis = require('./redis');
const apiClient = require('./apiClient');
const { printQueue } = require('./queue');

const DEDUPE_PREFIX = 'kazakora:print-agent:seen:';

/**
 * Uma rodada de polling: busca os jobs pendentes na API e enfileira só os
 * que ainda não foram vistos. Nunca lança — qualquer erro (API fora do ar,
 * timeout, Redis indisponível) só é logado, pra não derrubar o processo nem
 * interromper o `setInterval`. Nenhuma venda é perdida: se a API tiver
 * caído, o job continua "queued" do lado do servidor e vai aparecer nas
 * próximas rodadas.
 */
async function pollOnce() {
    let jobs;

    try {
        jobs = await apiClient.listPendingJobs();
    } catch (error) {
        logger.error('poller.api_unreachable', { message: error.message });

        return;
    }

    for (const job of jobs) {
        await enqueueIfNew(job);
    }
}

async function enqueueIfNew(job) {
    const dedupeKey = `${DEDUPE_PREFIX}${job.id}`;

    try {
        // SET ... NX EX: só grava (e retorna 1) se a chave ainda não
        // existir — dedupe atômico, sem race entre checar e gravar.
        const wasNew = await redis.set(dedupeKey, '1', 'EX', config.dedupeTtlSeconds, 'NX');

        if (!wasNew) {
            return;
        }

        await printQueue.add(
            'print-label',
            { printJobId: job.id, orderId: job.order_id },
            { jobId: String(job.id) },
        );

        logger.info('poller.job_enqueued', { print_job_id: job.id, order_id: job.order_id });
    } catch (error) {
        // Se der erro depois de marcar como "visto" no Redis mas antes de
        // conseguir enfileirar de verdade, desfaz a marca — senão essa
        // venda nunca mais seria tentada de novo dentro da janela de TTL.
        await redis.del(dedupeKey).catch(() => {});

        logger.error('poller.enqueue_failed', { print_job_id: job.id, message: error.message });
    }
}

function start() {
    logger.info('poller.started', { interval_ms: config.pollIntervalMs });

    pollOnce();

    return setInterval(pollOnce, config.pollIntervalMs);
}

module.exports = { start, pollOnce };
