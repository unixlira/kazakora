// Consulta manual da dead letter queue: `npm run dlq`.
// Jobs que esgotaram todas as tentativas (ver worker.js) ficam retidos na
// fila (removeOnFail: false, em queue.js) até serem revisados aqui.
const { printQueue } = require('./queue');

async function main() {
    const failed = await printQueue.getFailed(0, 100);

    if (failed.length === 0) {
        console.log('Nenhum job na dead letter queue. Tudo certo.');
        process.exit(0);
    }

    console.log(`${failed.length} job(s) na dead letter queue (falharam em todas as tentativas):\n`);

    for (const job of failed) {
        console.log(`- print_job_id=${job.data.printJobId} order_id=${job.data.orderId}`);
        console.log(`  tentativas: ${job.attemptsMade}/${job.opts.attempts}`);
        console.log(`  falhou em: ${new Date(job.finishedOn).toLocaleString('pt-BR')}`);
        console.log(`  motivo: ${job.failedReason}`);
        console.log(`  bull job id: ${job.id}\n`);
    }

    console.log('Pra reprocessar um job manualmente depois de resolver a causa (ex: reabastecer papel):');
    console.log('  node src/retry-job.js <bull_job_id>');

    process.exit(0);
}

main().catch((error) => {
    console.error('Erro consultando a dead letter queue:', error.message);
    process.exit(1);
});
