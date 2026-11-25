// Formatos da tela única de Leads (datas sempre no horário de São Paulo).
const FUSO = 'America/Sao_Paulo';

export const dinheiro = (valor) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor) || 0);

export const dataHora = (valor, segundos = false) => (valor
    ? new Date(valor).toLocaleString('pt-BR', {
        timeZone: FUSO, day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', ...(segundos ? { second: '2-digit' } : {}),
    })
    : '—');

// "agora", "há 12 min", "há 3 h", "há 2 dias".
export const relativo = (valor) => {
    if (!valor) return '—';
    const s = Math.max(0, Math.round((Date.now() - new Date(valor).getTime()) / 1000));
    if (s < 60) return 'agora';
    const min = Math.floor(s / 60);
    if (min < 60) return `há ${min} min`;
    const horas = Math.floor(min / 60);
    if (horas < 24) return `há ${horas} h`;
    const dias = Math.floor(horas / 24);
    return `há ${dias} ${dias === 1 ? 'dia' : 'dias'}`;
};
