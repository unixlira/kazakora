import { ref } from 'vue';

// PageSpeed (pedido 2026-10-21): na 1ª carga, as fotos que não são o topo da
// página (cards, categorias, outros banners, rodapé) só são pedidas depois que
// a loja montou e pintou a 1ª tela. Antes, ~30 fotos (~800 KB) saíam junto com
// o 1º banner e o PageSpeed contava a banda delas no tempo do banner (LCP
// 4,2 s no celular, com o banner real na tela em 0,5 s). Nas trocas de página
// seguintes já vale `true` e nada muda. Os lugares das fotos têm tamanho fixo,
// então a página não pula.
export const imagensLiberadas = ref(false);

export function liberarImagensDepoisDaPrimeiraPintura() {
    if (imagensLiberadas.value) return;
    const liberar = () => { imagensLiberadas.value = true; };
    // Dois quadros: o 1º desenha a página montada, o 2º já está na tela.
    requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(liberar, 0)));
    // Garantia para aba em segundo plano (sem quadros, o requestAnimationFrame não roda).
    setTimeout(liberar, 1500);
}
