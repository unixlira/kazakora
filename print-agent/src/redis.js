const IORedis = require('ioredis');
const config = require('./config');
const logger = require('./logger');

// maxRetriesPerRequest: null é exigido pelo BullMQ pra conexões usadas em
// Worker/QueueEvents (ele mesmo controla o retry das chamadas bloqueantes).
const connection = new IORedis(config.redisUrl, {
    maxRetriesPerRequest: null,
});

connection.on('error', (error) => {
    logger.error('redis.connection_error', { message: error.message });
});

connection.on('connect', () => {
    logger.info('redis.connected', { url: config.redisUrl });
});

module.exports = connection;
