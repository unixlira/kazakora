/**
 * Cor e ícone do selo de TIPO DE ENVIO (Flex, Mercado Envios, Full, Shopee
 * Xpress, coleta...) — pedido explícito 2026-09-14. O texto e o slug vêm
 * prontos do backend (App\Modules\Marketplace\Support\TipoDeEnvio), aqui só
 * mora a parte visual.
 *
 * As cores NÃO são a cor do canal (essa é o selo do lado, ver
 * channelBrand.js): duas vendas do mesmo canal podem sair de jeitos
 * diferentes, e é justamente essa diferença que o operador precisa ver de
 * longe. São cores de AÇÃO:
 *
 * - Flex é âmbar: sai hoje, com o entregador, tem hora pra acabar.
 * - Full é roxo e com cara de aviso: esse pedido NÃO se separa aqui, o
 *   estoque é do Mercado Livre — ver o pedido #2147/#2146 que apareceram na
 *   fila em 14/09.
 * - Retirada é verde-água: o cliente vem buscar, não pode entrar no lote
 *   que vai pra transportadora.
 * - O resto (coleta, agência, Xpress) é azul neutro: fluxo normal de
 *   despacho, não muda o que o operador faz.
 */
const ESTILOS = {
    flex: { cor: '#F5B301', icone: 'fa-bolt' },
    full: { cor: '#A78BFA', icone: 'fa-warehouse' },
    mercado_envios: { cor: '#4DA3FF', icone: 'fa-truck' },
    shopee_xpress: { cor: '#4DA3FF', icone: 'fa-truck' },
    coleta: { cor: '#4DA3FF', icone: 'fa-truck-ramp-box' },
    retirada: { cor: '#04D7B6', icone: 'fa-person-walking' },
    proprio: { cor: '#4DA3FF', icone: 'fa-truck' },
    outro: { cor: '#8B8DA0', icone: 'fa-truck' },
    desconhecido: { cor: '#8B8DA0', icone: 'fa-circle-question' },
};

export function shippingBadge(order) {
    const tipo = order?.shipping_type ?? 'desconhecido';
    const estilo = ESTILOS[tipo] ?? ESTILOS.desconhecido;

    return {
        tipo,
        texto: order?.shipping_type_short || '—',
        titulo: order?.shipping_type_label || 'Envio não informado',
        ...estilo,
    };
}
