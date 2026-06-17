<script setup>
defineOptions({
    inheritAttrs: false,
});
</script>

<template>
    <img
        src="/images/logo.png"
        alt="Logo"
        class="block h-auto object-contain"
        v-bind="$attrs"
    />
</template>
