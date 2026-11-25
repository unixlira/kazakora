// Efeitos de rolagem da página de brinquedo (pedido do Lira 24/11/2026, "igual à loja de referência,
// com as mesmas funcionalidades de efeito"). Código nosso; o comportamento segue o da referência:
//
// - Entrada ao rolar: o que já está na tela ao abrir aparece na hora (nunca fica conteúdo invisível
//   esperando animação). O que está abaixo da dobra nasce um pouco abaixo e transparente (.pb-pre) e
//   sobe com fade quando o topo passa de 90% da altura da tela (.is-in). Listas marcadas com .is-in
//   fazem os itens "pularem" um depois do outro (CSS).
// - Com "reduzir movimento" ligado nada fica escondido: tudo entra já com .is-in.
// - Se o navegador não rolar (aba de impressão, robô do Google), um tempo de segurança mostra tudo.
const REDUZIR = '(prefers-reduced-motion: reduce)';

export function ligarEntradas(raiz) {
    if (!raiz || typeof window === 'undefined') return () => {};
    const itens = [...raiz.querySelectorAll('.pb-reveal')];
    if (window.matchMedia?.(REDUZIR).matches || !('requestAnimationFrame' in window)) {
        itens.forEach((el) => el.classList.add('is-in'));
        return () => {};
    }
    const altura = window.innerHeight;
    let pendentes = itens.filter((el) => {
        if (el.getBoundingClientRect().top > altura) { el.classList.add('pb-pre'); return true; }
        el.classList.add('is-in');
        return false;
    });
    let quadro = false;
    const conferir = () => {
        quadro = false;
        const limite = window.innerHeight * 0.9;
        pendentes = pendentes.filter((el) => {
            if (el.getBoundingClientRect().top < limite) { el.classList.remove('pb-pre'); el.classList.add('is-in'); return false; }
            return true;
        });
        if (!pendentes.length) parar();
    };
    const aoRolar = () => { if (!quadro) { quadro = true; requestAnimationFrame(conferir); } };
    const mostrarTudo = () => { pendentes.forEach((el) => { el.classList.remove('pb-pre'); el.classList.add('is-in'); }); pendentes = []; parar(); };
    // Segurança: quem abre e vai direto para uma âncora (#avaliacoes) ou imprime vê tudo.
    const seguranca = setTimeout(() => { if (document.visibilityState === 'hidden') mostrarTudo(); }, 8000);
    function parar() {
        window.removeEventListener('scroll', aoRolar);
        window.removeEventListener('resize', aoRolar);
        window.removeEventListener('beforeprint', mostrarTudo);
        clearTimeout(seguranca);
    }
    window.addEventListener('scroll', aoRolar, { passive: true });
    window.addEventListener('resize', aoRolar, { passive: true });
    window.addEventListener('beforeprint', mostrarTudo);
    conferir();
    return parar;
}

/** Barra fixa de compra: aparece depois que o botão de compra da caixa sai da tela por cima. */
export function vigiarBotaoCompra(alvo, aoMudar) {
    if (typeof window === 'undefined') return () => {};
    let quadro = false;
    const conferir = () => {
        quadro = false;
        const el = typeof alvo === 'function' ? alvo() : alvo;
        aoMudar(Boolean(el) && el.getBoundingClientRect().bottom < 0);
    };
    const aoRolar = () => { if (!quadro) { quadro = true; requestAnimationFrame(conferir); } };
    window.addEventListener('scroll', aoRolar, { passive: true });
    window.addEventListener('resize', aoRolar, { passive: true });
    conferir();
    return () => { window.removeEventListener('scroll', aoRolar); window.removeEventListener('resize', aoRolar); };
}

/** Tabela "Parcele em até": 1x até o máximo, com a taxa real que vem do servidor (props.parcelamento). */
export function tabelaDeParcelas(preco, tabela) {
    const valor = Number(preco);
    const linhas = [{ parcelas: 1, valor, total: valor, juros: false }];
    for (const opcao of tabela ?? []) {
        if (opcao.parcelas < 2 || valor < opcao.minimo) continue;
        const total = Math.round(valor * (100 + opcao.taxa)) / 100;
        linhas.push({ parcelas: opcao.parcelas, valor: Math.round((total * 100) / opcao.parcelas + 1e-7) / 100, total, juros: opcao.taxa > 0 });
    }
    return linhas;
}
