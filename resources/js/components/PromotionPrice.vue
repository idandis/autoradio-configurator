<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ price: number; percentage?: number; locale?: string }>(), { percentage: 0, locale: 'it' });
const format = computed(() => new Intl.NumberFormat(props.locale, { style: 'currency', currency: 'EUR' }));
const discounted = computed(() => {
    const cents = Math.round(props.price * 100);
    return (cents - Math.floor((cents * props.percentage + 50) / 100)) / 100;
});
</script>

<template>
    <span class="promotion-price">
        <del v-if="percentage > 0" aria-label="Prezzo di listino">{{ format.format(price) }}</del>
        <strong v-if="percentage > 0" aria-label="Prezzo scontato">{{ format.format(discounted) }}</strong>
        <span v-else>{{ format.format(price) }}</span>
    </span>
</template>

<style scoped>
.promotion-price{display:inline-flex;align-items:baseline;gap:.5em;white-space:nowrap}.promotion-price del{color:#a3a3a3;font-size:1em;font-weight:inherit;text-decoration-thickness:1px}.promotion-price strong{color:#facc15;font-weight:800}
</style>
