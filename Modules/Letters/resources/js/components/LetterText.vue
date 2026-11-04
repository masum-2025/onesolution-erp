<script setup>
import { computed } from 'vue';
import { lines } from '../lib';

/** Letter text with its **bold**, *italic* and __underline__ marks and line breaks, as plain elements (never HTML). */
const props = defineProps({ text: { type: String, default: '' } });
const parts = computed(() => lines(props.text));
</script>

<template>
    <template v-for="(line, index) in parts" :key="index">
        <br v-if="index > 0" />
        <span
            v-for="(piece, at) in line"
            :key="at"
            :class="{ 'font-semibold': piece.bold, italic: piece.italic, underline: piece.underline }"
        >{{ piece.text }}</span>
    </template>
</template>
