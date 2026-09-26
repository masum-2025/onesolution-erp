<script setup>
import { computed } from 'vue';

/**
 * A legal document's plain text as readable blocks: a line starting with
 * "# " is a heading, a blank line starts a paragraph. Text only; nothing
 * is rendered as HTML.
 */
const props = defineProps({ text: { type: String, default: '' } });

const blocks = computed(() =>
    props.text
        .replace(/\r\n/g, '\n')
        .split(/\n\s*\n/)
        .flatMap((chunk) => {
            const lines = chunk.split('\n');
            const out = [];
            let paragraph = [];
            for (const line of lines) {
                if (line.startsWith('# ')) {
                    if (paragraph.length) out.push({ type: 'p', lines: paragraph });
                    paragraph = [];
                    out.push({ type: 'h', text: line.slice(2).trim() });
                } else if (line.trim()) {
                    paragraph.push(line.trim());
                }
            }
            if (paragraph.length) out.push({ type: 'p', lines: paragraph });
            return out;
        }),
);
</script>

<template>
    <div class="space-y-3 text-[13.5px] leading-relaxed text-fg-2">
        <template v-for="(block, index) in blocks" :key="index">
            <h3 v-if="block.type === 'h'" class="pt-2 text-[14px] font-semibold text-fg">{{ block.text }}</h3>
            <p v-else>
                <template v-for="(line, lineIndex) in block.lines" :key="lineIndex">{{ line }}<br v-if="lineIndex < block.lines.length - 1" /></template>
            </p>
        </template>
    </div>
</template>
