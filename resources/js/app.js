import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Keep a visible build revision in the entry bundle so CDN/browser caches
// receive a new asset URL after frontend releases.
document.documentElement.dataset.frontendBuild = '2026-11-03-1';

const openExternalLinks = (event) => {
    const link = event.target instanceof Element ? event.target.closest('a, area') : null;
    const form = event.type === 'submit' ? event.target : null;
    const element = link ?? form;
    if (!element) return;
    const url = new URL(link?.href ?? event.submitter?.getAttribute('formaction') ?? form?.action ?? '', window.location.href);
    if (!['http:', 'https:'].includes(url.protocol) || url.origin === window.location.origin) return;
    element.setAttribute('target', '_blank');
    if (link) {
        link.setAttribute('rel', 'noopener noreferrer');
        if (event.button === 0) {
            event.preventDefault();
            event.stopImmediatePropagation();
            window.open(url.href, '_blank', 'noopener,noreferrer');
        }
    } else if (event.submitter) event.submitter.setAttribute('formtarget', '_blank');
};
document.addEventListener('click', openExternalLinks, true);
document.addEventListener('submit', openExternalLinks, true);

createInertiaApp({
    title: (title) => title || appName,
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob('./pages/**/*.vue'),
        );

        if (!page.default.layout) {
            if (name.startsWith('Auth/')) {
                const { default: AuthLayout } = await import('@/layouts/AuthLayout.vue');
                page.default.layout = AuthLayout;
            } else if (name.startsWith('settings/')) {
                const [{ default: AppLayout }, { default: SettingsLayout }] = await Promise.all([
                    import('@/layouts/AppLayout.vue'),
                    import('@/layouts/settings/Layout.vue'),
                ]);
                page.default.layout = (createElement, pageNode) =>
                    createElement(AppLayout, null, {
                        default: () =>
                            createElement(SettingsLayout, null, {
                                default: () => pageNode,
                            }),
                    });
            } else if (!['Welcome', 'Configurator', 'ItalianStorePage', 'ItalianHome'].includes(name)) {
                const { default: AppLayout } = await import('@/layouts/AppLayout.vue');
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
