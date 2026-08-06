// Script de validação manual (não faz parte do agente em produção) —
// exercita apiClient.js contra a API real, sem precisar de Redis/BullMQ,
// pra confirmar que a integração HTTP (claim -> download -> complete) está
// correta antes de depender da fila. Roda uma vez e sai.
require('dotenv').config();
const apiClient = require('./apiClient');

async function main() {
    console.log('1. Listando jobs pendentes...');
    const jobs = await apiClient.listPendingJobs();
    console.log(`   -> ${jobs.length} job(s):`, jobs);

    if (jobs.length === 0) {
        console.log('Nenhum job pendente pra testar. Encerrando.');
        return;
    }

    const target = jobs[0];
    console.log(`\n2. Reivindicando job ${target.id}...`);
    const claimed = await apiClient.claimJob(target.id);

    if (!claimed) {
        console.log('   -> já estava reivindicado por outro agente (409). Encerrando sem alterar nada.');
        return;
    }

    console.log('   -> reivindicado:', claimed);

    console.log(`\n3. Baixando PDF do job ${target.id}...`);
    const pdf = await apiClient.downloadLabel(target.id);
    console.log(`   -> ${pdf.length} bytes recebidos, header: ${pdf.slice(0, 8).toString('latin1')}`);

    if (!pdf.slice(0, 5).toString('latin1').startsWith('%PDF-')) {
        throw new Error('Conteúdo baixado não parece ser um PDF válido.');
    }

    console.log('\n4. Reportando sucesso (SEM imprimir de verdade — este é só o teste de integração)...');
    await apiClient.reportComplete(target.id, 'printed');
    console.log('   -> reportado com sucesso.');

    console.log('\nTeste de integração OK: claim -> download -> complete funcionaram contra a API real.');
}

main().catch((error) => {
    console.error('Falha no teste de integração:', error.response?.data || error.message);
    process.exit(1);
});
