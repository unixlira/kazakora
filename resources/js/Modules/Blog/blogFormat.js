// Formatação do Caderno KazaKora (blog). Só texto: nada aqui gera HTML.
const TZ = 'America/Sao_Paulo';

export const TYPES = {
    news: { label: 'Notícia', section: 'Notícias' },
    recipe: { label: 'Receita', section: 'Receitas' },
    video: { label: 'Vídeo', section: 'Vídeos' },
};

export const typeLabel = (type) => TYPES[type]?.label || 'Matéria';

function toDate(iso) {
    const d = iso ? new Date(iso) : null;
    return d && !Number.isNaN(d.getTime()) ? d : null;
}

export function longDate(iso) {
    const d = toDate(iso);
    return d ? new Intl.DateTimeFormat('pt-BR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ }).format(d) : '';
}

export function shortDate(iso) {
    const d = toDate(iso);
    return d ? new Intl.DateTimeFormat('pt-BR', { day: 'numeric', month: 'short', timeZone: TZ }).format(d).replace('.', '') : '';
}

export function todayLine(now = new Date()) {
    return new Intl.DateTimeFormat('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: TZ }).format(now);
}

export function readingMinutes(text) {
    const words = String(text || '').trim().split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.round(words / 200));
}

// Quebra o corpo (texto puro) em blocos para a página da matéria:
// - "Ingredientes: ..." vira parágrafo com rótulo em destaque;
// - linha curta sozinha, sem pontuação final, vira intertítulo.
export function paragraphs(body) {
    return String(body || '')
        .split(/\n\s*\n/)
        .map((block) => block.trim())
        .filter(Boolean)
        .map((block) => {
            const labeled = block.match(/^([^\n:.!?]{2,28}):\s+([\s\S]+)$/);
            if (labeled) return { kind: 'p', label: labeled[1], text: labeled[2] };
            if (!block.includes('\n') && block.length <= 60 && !/[.!?:;,…]$/.test(block)) return { kind: 'h', label: '', text: block };
            return { kind: 'p', label: '', text: block };
        });
}

export function blogUrl(params = {}) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) if (value) query.set(key, value);
    const qs = query.toString();
    return qs ? `/blog?${qs}` : '/blog';
}

export const brl = (value) => {
    const n = Number(value);
    return Number.isFinite(n) && n > 0 ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(n) : '';
};
