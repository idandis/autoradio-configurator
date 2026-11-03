<script setup lang="ts">
import { onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import HomeBetaContent from '@/components/HomeBetaContent.vue';
import ItalianStoreHeader from '@/components/ItalianStoreHeader.vue';
import ItalianStoreFooter from '@/components/ItalianStoreFooter.vue';
import { trackVisitorEntry } from '@/lib/visitorStatistics';

defineProps<{ stockProducts: InstanceType<typeof HomeBetaContent>['$props']['products'] }>();

onMounted(() => {
    document.documentElement.lang = 'it';
    void trackVisitorEntry('it');
});
</script>

<template>
    <Head title="Autoradio Italiano · Home" />
    <div class="min-h-screen w-full max-w-full overflow-x-clip bg-[#121212] text-white">
        <ItalianStoreHeader :cart-count="0" @cart="router.visit('/configurator?lang=it&cart=1')" />
        <HomeBetaContent :products="stockProducts" @select="product => router.visit(`/configurator?lang=it&stock=${product.id}`)" />
        <ItalianStoreFooter />
    </div>
</template>
