<script setup>
import { computed } from 'vue';
import { Bell, BookOpen, ChartColumn, DoorOpen, FileText, GalleryHorizontal, Images, Phone, Sparkles, Text, TrendingUp, Users } from 'lucide-vue-next';

/** The icon of a kind of block (names from BLOCKS in ../lib.js). */
const props = defineProps({ name: { type: String, required: true } });

const ICONS = { sparkles: Sparkles, text: Text, bell: Bell, door: DoorOpen, book: BookOpen, users: Users, images: Images, file: FileText, phone: Phone, chart: ChartColumn, slides: GalleryHorizontal, trend: TrendingUp };
const icon = computed(() => ICONS[props.name] ?? Text);
</script>

<template>
    <component :is="icon" aria-hidden="true" />
</template>
