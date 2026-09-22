<script setup lang="ts">
defineProps<{
    title: string;
    image?: string | null;
    detailsLabel: string;
    detailsAriaLabel: string;
    imageUnavailableLabel: string;
    priceLabel: string;
    primaryLabel: string;
    selected?: boolean;
}>();

defineEmits<{ primary: []; details: [] }>();
</script>

<template>
    <article
        class="product-card"
        :class="selected ? 'product-card-selected' : ''"
    >
        <div class="grid gap-2">
            <div v-if="$slots.tag" class="flex justify-center">
                <slot name="tag" />
            </div>
            <button
                type="button"
                class="product-details-button"
                :aria-label="detailsAriaLabel"
                @click="$emit('details')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                    <circle cx="12" cy="12" r="2.5" />
                </svg>
                <span>{{ detailsLabel }}</span>
            </button>
        </div>

        <button
            type="button"
            class="product-image-button"
            :aria-label="detailsAriaLabel"
            @click="$emit('details')"
        >
            <img v-if="image" :src="image" :alt="title" loading="lazy" decoding="async" />
            <span v-else class="text-sm text-neutral-500">{{ imageUnavailableLabel }}</span>
        </button>

        <slot name="options" />

        <div class="min-w-0">
            <h3 class="product-card-title">{{ title }}</h3>
            <slot name="subtitle" />
            <p class="product-card-price">{{ priceLabel }}</p>
        </div>

        <slot name="quantity" />

        <button
            type="button"
            class="product-primary-button"
            :class="selected ? 'product-primary-button-selected' : ''"
            @click="$emit('primary')"
        >
            {{ primaryLabel }}
        </button>
    </article>
</template>

<style scoped>
.product-card {
    display: grid;
    align-content: start;
    gap: 0.75rem;
    min-width: 0;
    height: 100%;
    padding: 1rem;
    overflow: hidden;
    border: 1px solid #262626;
    border-radius: 0.75rem;
    background: #121212;
    transition: border-color 150ms ease, background-color 150ms ease, box-shadow 150ms ease;
}
.product-card:hover { border-color: #404040; }
.product-card-selected { border-color: #fbbf24; background: rgba(251, 191, 36, 0.1); box-shadow: 0 0 0 1px #fbbf24; }
.product-details-button {
    display: inline-flex;
    min-height: 44px;
    margin-inline: auto;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    border: 1px solid #fbbf24;
    border-radius: 0.5rem;
    background: #121212;
    padding: 0.625rem 0.875rem;
    color: #fbbf24;
    font-size: 0.875rem;
    font-weight: 700;
    transition: transform 100ms ease, background-color 150ms ease, color 150ms ease, box-shadow 150ms ease;
}
.product-details-button svg { width: 1.125rem; height: 1.125rem; flex: none; }
.product-details-button:hover { background: rgba(251, 191, 36, 0.12); }
.product-details-button:focus-visible, .product-image-button:focus-visible, .product-primary-button:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.product-details-button:active, .product-image-button:active, .product-primary-button:active { transform: scale(0.98); }
.product-image-button {
    display: flex;
    width: 100%;
    min-height: 11rem;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border: 1px solid #262626;
    border-radius: 0.5rem;
    background: #0a0a0a;
    transition: transform 100ms ease, border-color 150ms ease, background-color 150ms ease;
}
.product-image-button:hover { border-color: #fbbf24; background: #171717; }
.product-image-button img { width: 100%; height: 11rem; padding: 0.5rem; object-fit: contain; object-position: center; }
.product-card-title {
    display: -webkit-box;
    overflow: hidden;
    color: #fff;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.45;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
}
.product-card-price { margin-top: 0.625rem; color: #fbbf24; font-size: 1.125rem; font-weight: 700; line-height: 1.5rem; }
.product-primary-button {
    width: 100%;
    min-height: 44px;
    border-radius: 0.5rem;
    background: #fbbf24;
    padding: 0.625rem 0.75rem;
    color: #000;
    font-size: 0.875rem;
    font-weight: 800;
    transition: transform 100ms ease, background-color 150ms ease, box-shadow 150ms ease;
}
.product-primary-button:hover { background: #fcd34d; }
.product-primary-button-selected { border: 1px solid #ef4444; background: rgba(239, 68, 68, 0.1); color: #fca5a5; }
.product-primary-button-selected:hover { background: #ef4444; color: #fff; }
@media (min-width: 640px) {
    .product-image-button, .product-image-button img { min-height: 10rem; height: 10rem; }
}
</style>
