<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';

const page = usePage();
const appName = computed(() => page.props.name ?? 'Laravel');
</script>

<template>
    <div
        class="flex h-8 items-center justify-center"
    >
        <AppLogoIcon class="h-8 w-auto max-w-none" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold"
            >{{ appName }}</span
        >
    </div>
</template>
