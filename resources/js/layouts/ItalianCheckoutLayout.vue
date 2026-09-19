<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
const page = usePage();
const locale = page.props.checkoutLocale === 'es' ? 'es' : 'it';
</script>
<template>
    <div class="min-h-screen bg-[#121212] text-neutral-100">
        <header class="border-b border-neutral-800">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-5 py-5">
                <a :href="`/configurator?lang=${locale}`" class="text-lg font-bold tracking-wide">AUTORADIO<span class="text-amber-400">{{ locale === 'es' ? 'CANARIO' : 'ITALIANO' }}</span></a>
                <span class="rounded-full border border-amber-400/40 px-3 py-1 text-xs text-amber-300">{{ page.props.isTest ? (locale === 'es' ? 'Modo de prueba · ningún cargo' : 'Modalità prova · nessun addebito') : (locale === 'es' ? 'Pago seguro · Stripe' : 'Pagamento sicuro · Stripe') }}</span>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-5 py-8 sm:py-12"><slot /></main>
    </div>
</template>
