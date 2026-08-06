const path = require('path');
const winston = require('winston');
require('winston-daily-rotate-file');
const config = require('./config');

const fileTransport = new winston.transports.DailyRotateFile({
    dirname: config.logDir,
    filename: '%DATE%.log',
    datePattern: 'YYYY-MM-DD',
    maxFiles: '30d',
    zippedArchive: true,
});

// winston.format.timestamp() sem opções sempre grava em UTC
// (new Date().toISOString()) — força horário de São Paulo aqui, já que os
// jobs/testes são todos relativos ao horário local de operação da loja.
const timestampSaoPaulo = () => new Date()
    .toLocaleString('sv-SE', { timeZone: 'America/Sao_Paulo' })
    .replace(' ', 'T');

const logger = winston.createLogger({
    level: config.logLevel,
    format: winston.format.combine(
        winston.format.timestamp({ format: timestampSaoPaulo }),
        winston.format.errors({ stack: true }),
        winston.format.json(),
    ),
    transports: [
        fileTransport,
        new winston.transports.Console({
            format: winston.format.combine(
                winston.format.colorize(),
                winston.format.simple(),
            ),
        }),
    ],
});

module.exports = logger;
