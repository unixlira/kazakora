// Opções de desconto e preço dos filtros da loja (FiltrosCatalogo.vue). As
// faixas de preço são as mesmas de CatalogController::FAIXAS_PRECO.
export const DESCONTOS = [
    { valor: null, label: 'Qualquer desconto' },
    { valor: '10', label: '10% ou mais' },
    { valor: '20', label: '20% ou mais' },
    { valor: '30', label: '30% ou mais' },
    { valor: '40', label: '40% ou mais' },
];

export const PRECOS = [
    { valor: null, label: 'Qualquer preço' },
    { valor: 'ate-50', label: 'Até R$ 50' },
    { valor: '50-100', label: 'R$ 50 a R$ 100' },
    { valor: '100-200', label: 'R$ 100 a R$ 200' },
    { valor: '200-mais', label: 'Acima de R$ 200' },
];
