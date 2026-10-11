import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'KazaKora';

createInertiaApp({
    title: (title) => (title ? `${appName} - ${title}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Modules/${name}.vue`,
            import.meta.glob('./Modules/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);

        // Tela de abertura (resources/views/app.blade.php): some quando a loja montou.
        const abertura = document.getElementById('tela-abertura');
        if (abertura) {
            requestAnimationFrame(() => abertura.classList.add('saindo'));
            setTimeout(() => abertura.remove(), 600);
        }
    },
    // Barra no topo nas trocas de página (a mesma de sempre); aparece aos
    // 150 ms em vez de 250 pro cliente ver logo que está carregando.
    progress: {
        color: '#4B5563',
        delay: 150,
    },
});
