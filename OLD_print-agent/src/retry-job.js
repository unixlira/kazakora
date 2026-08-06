// Reprocessa manualmente um job da dead letter queue depois de resolver a
// causa raiz (ex: reabasteceu papel, impressora voltou a ficar online).
// Uso: node src/retry-job.js <bull_job_id>   (bull_job_id vem do `npm run dlq`)
const { printQueue } = require('./queue');

async function main() {
    const bullJobId = process.argv[2];

    if (!bullJobId) {
        console.error('Uso: node src/retry-job.js <bull_job_id>');
        process.exit(1);
    }

    const job = await printQueue.getJob(bullJobId);

    if (!job) {
        console.error(`Job ${bullJobId} não encontrado.`);
        process.exit(1);
    }

    await job.retry();

    console.log(`Job ${bullJobId} (print_job_id=${job.data.printJobId}) reenviado para a fila.`);
    process.exit(0);
}

main().catch((error) => {
    console.error('Erro reprocessando o job:', error.message);
    process.exit(1);
});
