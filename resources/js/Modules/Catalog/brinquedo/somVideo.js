// Som do vídeo da página de brinquedo (pedido do Lira 25/11/2026): o botão redondo fixo à esquerda
// controla o som do VÍDEO, igual ao botão do adesivo ao lado do vídeo. O "Aeeee!" das crianças é
// separado: toca sozinho uma vez ao abrir e não depende desse botão.
import { reactive } from 'vue';

export const somVideo = reactive({
    existe: false, // a página tem vídeo
    mudo: true,
    alternar: () => {},
});
