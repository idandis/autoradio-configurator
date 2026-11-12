<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CatalogVariantMatcher from '@/components/CatalogVariantMatcher.vue';

defineProps<{ product: { id: number; title: string }; allowReplacement: boolean }>();

function back() {
    if (window.opener && !window.opener.closed) {
        window.opener.location.reload();
        window.close();
    }
    if (!window.closed) window.location.href = '/imported-products';
}
</script>

<template>
    <Head :title="`Varianti · ${product.title}`" />
    <main class="w-full space-y-4 p-4 lg:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Varianti · {{ product.title }}</h1>
                <p class="text-sm text-muted-foreground">Confronto tra varianti app e AliExpress</p>
            </div>
            <button type="button" class="rounded border px-4 py-2 text-sm" @click="back">← Indietro</button>
        </div>
        <CatalogVariantMatcher :product-id="product.id" :allow-replacement="allowReplacement" auto-load />
    </main>
</template>
