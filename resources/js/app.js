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
    },
    progress: {
        color: '#4B5563',
    },
});
