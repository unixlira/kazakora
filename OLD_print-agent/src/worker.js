const { Worker } = require('bullmq');
const connection = require('./redis');
const config = require('./config');
const logger = require('./logger');
const apiClient = require('./apiClient');
const printer = require('./printer');
const { QUEUE_NAME } = require('./queue');

/**
 * Processa um job da fila: reivindica -> baixa o PDF -> imprime -> reporta.
 * Qualquer exceção lançada aqui faz o BullMQ agendar o retry automático
 * (attempts/backoff configurados em queue.js) — não precisa de try/catch
 * "engolindo" erro, o comportamento certo é deixar propagar.
 *
 * Reivindicação já feita por OUTRO agente (409) não é falha: só encerra o
 * job como concluído sem imprimir nada aqui, porque outra máquina já está
 * cuidando dele.
 */
async function processJob(job) {
    const { printJobId, orderId } = job.data;

    logger.info('worker.processing', { print_job_id: printJobId, order_id: orderId, attempt: job.attemptsMade + 1 });

    const claimed = await apiClient.claimJob(printJobId);

    if (!claimed) {
        logger.info('worker.already_claimed_elsewhere', { print_job_id: printJobId });

        return { skipped: true };
    }

    const pdfBuffer = await apiClient.downloadLabel(printJobId);

    await printer.printPdfBuffer(pdfBuffer, `label-${printJobId}`);

    await apiClient.reportComplete(printJobId, 'printed');

    logger.info('worker.printed', { print_job_id: printJobId, order_id: orderId });

    return { printed: true };
}

function start() {
    const worker = new Worker(
        QUEUE_NAME,
        processJob,
        {
            connection,
            concurrency: 1, // a impressora é um recurso sequencial — nunca dois jobs em paralelo
        },
    );

    worker.on('failed', async (job, error) => {
        logger.error('worker.job_failed', {
            print_job_id: job?.data?.printJobId,
            attempt: job?.attemptsMade,
            max_attempts: job?.opts?.attempts,
            message: error.message,
        });

        const exhausted = job && job.attemptsMade >= job.opts.attempts;

        if (exhausted) {
            logger.error('worker.job_moved_to_dlq', { print_job_id: job.data.printJobId, order_id: job.data.orderId });

            await apiClient
                .reportComplete(job.data.printJobId, 'failed', `Falhou após ${job.opts.attempts} tentativas: ${error.message}`)
                .catch((reportError) => {
                    logger.error('worker.report_failed_error', { print_job_id: job.data.printJobId, message: reportError.message });
                });
        }
    });

    worker.on('error', (error) => {
        logger.error('worker.internal_error', { message: error.message });
    });

    logger.info('worker.started', { printer: config.printerName, max_attempts: config.printMaxAttempts });

    return worker;
}

module.exports = { start };
