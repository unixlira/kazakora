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

const logger = winston.createLogger({
    level: config.logLevel,
    format: winston.format.combine(
        winston.format.timestamp(),
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
