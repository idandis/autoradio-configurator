<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';

const props = defineProps<{ modelValue: string; label: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const editor = ref<HTMLDivElement | null>(null);
const sync = () => {
    if (editor.value && editor.value.innerHTML !== props.modelValue) editor.value.innerHTML = props.modelValue;
};
const update = () => emit('update:modelValue', editor.value?.innerHTML ?? '');
const format = (command: string) => {
    editor.value?.focus();
    document.execCommand(command);
    update();
};
const paste = (event: ClipboardEvent) => {
    event.preventDefault();
    document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
    update();
};
onMounted(sync);
watch(() => props.modelValue, sync);
</script>

<template>
    <div class="rounded-lg border border-sidebar-border/70">
        <div class="flex gap-2 border-b border-sidebar-border/70 p-2" role="toolbar" :aria-label="`Formattazione ${label}`">
            <button type="button" class="rounded border px-3 py-1 font-bold" aria-label="Grassetto" @mousedown.prevent @click="format('bold')">B</button>
            <button type="button" class="rounded border px-3 py-1 italic" aria-label="Corsivo" @mousedown.prevent @click="format('italic')">I</button>
            <button type="button" class="rounded border px-3 py-1 text-sm" @mousedown.prevent @click="format('insertUnorderedList')">Elenco</button>
        </div>
        <div ref="editor" contenteditable="true" role="textbox" aria-multiline="true" :aria-label="label" class="description-editor min-h-48 max-h-[40vh] overflow-y-auto bg-background p-4 text-sm outline-none focus:ring-2 focus:ring-primary" @input="update" @paste="paste" />
    </div>
</template>

<style scoped>
.description-editor :deep(p){margin:.6em 0}.description-editor :deep(ul){list-style:disc;padding-left:1.5em}.description-editor :deep(ol){list-style:decimal;padding-left:1.5em}.description-editor :deep(img){max-width:100%;height:auto}.description-editor :deep(a){text-decoration:underline}.description-editor :deep(table){max-width:100%}
</style>
