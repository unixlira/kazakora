// Lista os nomes de impressora disponíveis nesta máquina Windows —
// use o valor exato de "name" no PRINTER_NAME do .env.
// Uso: npm run printers
const printer = require('./printer');

printer
    .listAvailablePrinters()
    .then((printers) => {
        if (printers.length === 0) {
            console.log('Nenhuma impressora encontrada nesta máquina.');

            return;
        }

        console.log('Impressoras disponíveis:\n');
        printers.forEach((p) => console.log(`- ${p.name}${p.deviceId ? ` (${p.deviceId})` : ''}`));
    })
    .catch((error) => {
        console.error('Erro listando impressoras:', error.message);
        process.exit(1);
    });
