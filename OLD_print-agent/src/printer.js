const fs = require('fs/promises');
const path = require('path');
const { print, getPrinters } = require('pdf-to-printer');
const config = require('./config');
const logger = require('./logger');

/**
 * `pdf-to-printer` só existe pra Windows (usa o SumatraPDF empacotado por
 * baixo dos panos e o spooler nativo do SO). Funciona igual pra impressora
 * USB ou de rede — uma vez que a impressora está instalada no Windows (com
 * o driver certo) ela aparece como um "nome de impressora" comum; a
 * distinção USB vs. TCP/IP fica inteiramente a cargo do Windows, não deste
 * código.
 */
async function printPdfBuffer(buffer, filenameHint) {
    await fs.mkdir(config.tempDir, { recursive: true });

    const tempPath = path.join(config.tempDir, `${filenameHint}.pdf`);
    await fs.writeFile(tempPath, buffer);

    try {
        await print(tempPath, { printer: config.printerName });
    } finally {
        await fs.unlink(tempPath).catch((error) => {
            logger.warn('printer.temp_cleanup_failed', { path: tempPath, message: error.message });
        });
    }
}

async function listAvailablePrinters() {
    return getPrinters();
}

module.exports = { printPdfBuffer, listAvailablePrinters };
