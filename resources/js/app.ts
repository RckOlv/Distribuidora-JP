import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

const appName = import.meta.env.VITE_APP_NAME || 'Verduleria';

type Pagina = { default: DefineComponent };

const paginas = import.meta.glob<Pagina>('./Pages/**/*.vue');

const resolver = (name: string) => {
    const importarPagina = paginas[`./Pages/${name}.vue`];

    if (!importarPagina) {
        throw new Error(`No se encontró la página: ${name}`);
    }

    return importarPagina().then((modulo) => modulo.default);
};

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolver(name),
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#16a34a',
    },
});
