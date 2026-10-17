// Selo do meio de envio do pedido no admin (listagem e detalhe usam o mesmo
// nome, pedido do Lira em 2026-10-16). O texto e o slug vêm de TipoDeEnvio no
// backend (shipping_type, shipping_type_short); aqui só se escolhe o nome que
// o Lira usa para cada canal:
//   site (KazaKora, FerraKora, Beauty Kora) -> Correios, com PAC/SEDEX
//     embaixo quando o serviço já é conhecido;
//   Mercado Livre -> Flex ou Mercado Envios (em duas linhas); Full fica Full;
//   Shopee -> Express (Retirada pelo comprador continua Retirada: esse pacote
//     não vai para a transportadora);
//   TikTok -> Ponto de Coleta.
// Amazon e o resto ficam como vêm de TipoDeEnvio. O KoraSync não muda: ele
// usa o nome de TipoDeEnvio, que é o que a bancada usa.

// Flex e Mercado Envios com cores diferentes de propósito: no Mercado Livre
// é essa a diferença que importa na bancada.
export const shippingTypeTone = {
    flex: 'yellow',
    full: 'purple',
    mercado_envios: 'blue',
    express_coleta: 'blue',
    retirada: 'green',
    correios: 'green',
    correios_pac: 'green',
    correios_sedex: 'green',
    proprio: 'gray',
    outro: 'gray',
    desconhecido: 'gray',
};

const servicoCorreios = {
    correios_pac: 'PAC',
    correios_sedex: 'SEDEX',
};

/**
 * @param {{ origin?: string, shipping_type?: string, shipping_type_short?: string }} order
 * @returns {{ tone: string, label: string }}
 */
export const shippingBadge = (order) => {
    const tipo = order.shipping_type ?? 'desconhecido';
    const padrao = { tone: shippingTypeTone[tipo] ?? 'gray', label: order.shipping_type_short || '—' };

    switch (order.origin) {
        case 'loja':
            return { tone: 'green', label: servicoCorreios[tipo] ? `Correios\n${servicoCorreios[tipo]}` : 'Correios' };
        case 'mercado_livre':
            if (tipo === 'flex') return { tone: shippingTypeTone.flex, label: 'Flex' };
            if (tipo === 'mercado_envios') return { tone: shippingTypeTone.mercado_envios, label: 'Mercado\nEnvios' };
            return padrao;
        case 'shopee':
            return tipo === 'retirada' ? padrao : { tone: shippingTypeTone.express_coleta, label: 'Express' };
        case 'tiktok_shop':
            return { tone: shippingTypeTone.express_coleta, label: 'Ponto de\nColeta' };
        default:
            return padrao;
    }
};
