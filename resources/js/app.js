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

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = await resolvePageComponent(
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
            } else if (name !== 'Welcome') {
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
