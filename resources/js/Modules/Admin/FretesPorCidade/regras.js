// Fretes por cidade (pedido 2026-10-24): mesmos textos de regra das janelas
// de Fretes por estado, mais o resumo dos limites da categoria de pacote.
export { TIPOS, descrever, normalizar } from '../FretesPorEstado/regraDeFrete';

export function limites(c) {
    if (c.peso_max_g === null && c.lado_max_cm === null) return 'acima disso';
    const kg = (c.peso_max_g / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 });

    return `até ${kg} kg e ${c.lado_max_cm} cm no maior lado`;
}
