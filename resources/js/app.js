import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeFlashToast } from '@/lib/flashToast';
import Configurator from '@/pages/Configurator.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Keep a visible build revision in the entry bundle so CDN/browser caches
// receive a new asset URL after frontend releases.
document.documentElement.dataset.frontendBuild = '2026-08-27-2';

createInertiaApp({
    title: (title) => title || appName,
    resolve: async (name) => {
        const page = name === 'Configurator'
            ? { default: Configurator }
            : await resolvePageComponent(
                `./pages/${name}.vue`,
                import.meta.glob('./pages/**/*.vue'),
            );

        if (!page.default.layout) {
            if (name.startsWith('Auth/')) {
                page.default.layout = AuthLayout;
            } else if (name.startsWith('settings/')) {
                page.default.layout = (createElement, pageNode) =>
                    createElement(AppLayout, null, {
                        default: () =>
                            createElement(SettingsLayout, null, {
                                default: () => pageNode,
                            }),
                    });
            } else if (!['Welcome', 'Configurator'].includes(name)) {
                page.default.layout = AppLayout;
            }
        }

        return page;
    },
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
initializeFlashToast();
