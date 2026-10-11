require('dotenv').config();

function required(name) {
    const value = process.env[name];

    if (!value) {
        throw new Error(`Variável de ambiente obrigatória ausente: ${name} (confira o .env)`);
    }

    return value;
}

module.exports = {
    apiBaseUrl: required('API_BASE_URL').replace(/\/+$/, ''),
    printAgentToken: required('PRINT_AGENT_TOKEN'),
    agentId: process.env.AGENT_ID || require('os').hostname(),
    printerName: required('PRINTER_NAME'),
    pollIntervalMs: Number(process.env.POLL_INTERVAL_MS || 5000),
    redisUrl: process.env.REDIS_URL || 'redis://127.0.0.1:6379',
    printMaxAttempts: Number(process.env.PRINT_MAX_ATTEMPTS || 5),
    printBackoffBaseMs: Number(process.env.PRINT_BACKOFF_BASE_MS || 2000),
    printKeepCompleted: Number(process.env.PRINT_KEEP_COMPLETED || 100),
    dedupeTtlSeconds: Number(process.env.DEDUPE_TTL_SECONDS || 86400),
    tempDir: process.env.TEMP_DIR || './tmp',
    logDir: process.env.LOG_DIR || './logs',
    logLevel: process.env.LOG_LEVEL || 'info',
};
