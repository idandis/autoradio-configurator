<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ItalianStoreHeader from '@/components/ItalianStoreHeader.vue';
import ItalianStoreFooter from '@/components/ItalianStoreFooter.vue';
defineProps<{ slug: string; page: { title: string; intro: string; sections: { title: string; paragraphs?: string[]; items?: string[] }[] } }>();
</script>

<template>
    <Head :title="`${page.title} · Autoradio Italiano`"><meta name="description" :content="page.intro" /></Head>
    <div class="min-h-screen bg-[#121212] text-white">
        <ItalianStoreHeader />
        <main class="mx-auto max-w-4xl px-5 py-12 sm:px-8 sm:py-16">
            <a href="/" class="text-sm text-emerald-400 hover:underline">← Home</a>
            <h1 class="mt-7 text-4xl font-bold tracking-tight sm:text-5xl">{{ page.title }}</h1>
            <p class="mt-6 text-lg leading-8 text-neutral-300">{{ page.intro }}</p>
            <img v-if="slug === 'chi-siamo'" src="/images/foto-chi-siamo.webp" alt="I fondatori di Autoradio Italiano: Paolo, Iana e Iulia" width="1024" height="576" class="mt-9 h-auto w-full rounded-2xl" />
            <section v-for="section in page.sections" :key="section.title" class="mt-9 border-t border-neutral-800 pt-7">
                <h2 class="text-xl font-semibold">{{ section.title }}</h2>
                <p v-for="paragraph in section.paragraphs" :key="paragraph" class="mt-4 whitespace-pre-line text-base leading-7 text-neutral-300">{{ paragraph }}</p>
                <ul v-if="section.items" class="mt-4 list-disc space-y-2 pl-5 text-base leading-7 text-neutral-300"><li v-for="item in section.items" :key="item">{{ item }}</li></ul>
            </section>
            <aside class="mt-10 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-6"><h2 class="text-xl font-semibold">Parliamone insieme</h2><p class="mt-3 text-neutral-300">Scrivi a <a href="mailto:info@autoradioitaliano.it" class="break-all text-emerald-400 underline">info@autoradioitaliano.it</a> o contattaci su <a href="https://wa.me/393514346911" class="text-emerald-400 underline">WhatsApp +39 351 434 6911</a>.</p><a v-if="slug === 'contatti' || slug === 'chi-siamo'" href="/configurator?lang=it&form=autoradio" class="mt-5 inline-block rounded-lg bg-emerald-600 px-5 py-3 font-semibold hover:bg-emerald-500">Richiedi assistenza per la tua auto</a></aside>
        </main>
        <ItalianStoreFooter />
    </div>
</template>
