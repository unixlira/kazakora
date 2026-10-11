const axios = require('axios');
const config = require('./config');

const client = axios.create({
    baseURL: `${config.apiBaseUrl}/api/print-agent`,
    timeout: 15000,
    headers: {
        Authorization: `Bearer ${config.printAgentToken}`,
    },
});

/**
 * Lista os jobs de impressão pendentes (status "queued") — é a "API de
 * vendas" que o Poller consulta a cada rodada. Cada item é { id, order_id,
 * created_at }; o conteúdo real da etiqueta só é baixado depois do claim.
 */
async function listPendingJobs() {
    const { data } = await client.get('/jobs');

    return data.jobs;
}

/**
 * Reivindica um job pra esta máquina (evita duas execuções do Worker
 * disputando o mesmo job). 409 = outro agente já pegou; tratado como
 * "não é mais nosso problema", não como erro.
 */
async function claimJob(jobId) {
    try {
        const { data } = await client.post(`/jobs/${jobId}/claim`, {
            agent_id: config.agentId,
        });

        return data.job;
    } catch (error) {
        if (error.response?.status === 409) {
            return null;
        }

        throw error;
    }
}

/**
 * Baixa o PDF da etiqueta (só funciona depois do claim — servidor recusa
 * com 409 caso contrário).
 */
async function downloadLabel(jobId) {
    const { data } = await client.get(`/jobs/${jobId}/label`, {
        responseType: 'arraybuffer',
    });

    return Buffer.from(data);
}

/**
 * Reporta o resultado da impressão pro servidor — dispara a timeline do
 * pedido e, em caso de falha, a notificação de admin (já existentes no
 * Laravel).
 */
async function reportComplete(jobId, status, errorMessage) {
    await client.post(`/jobs/${jobId}/complete`, {
        status,
        error_message: errorMessage ?? null,
    });
}

module.exports = {
    listPendingJobs,
    claimJob,
    downloadLabel,
    reportComplete,
};
