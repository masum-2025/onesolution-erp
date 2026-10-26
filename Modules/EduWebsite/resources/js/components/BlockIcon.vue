<script setup>
import { computed } from 'vue';
import { Bell, BookOpen, CalendarDays, ChartColumn, DoorOpen, FileText, GalleryHorizontal, Images, MessageSquareText, Phone, Quote, Sparkles, Text, TrendingUp, Users } from 'lucide-vue-next';

/** The icon of a kind of block (names from BLOCKS in ../lib.js). */
const props = defineProps({ name: { type: String, required: true } });

const ICONS = { sparkles: Sparkles, text: Text, bell: Bell, door: DoorOpen, book: BookOpen, users: Users, images: Images, file: FileText, phone: Phone, chart: ChartColumn, slides: GalleryHorizontal, trend: TrendingUp, message: MessageSquareText, quote: Quote, calendar: CalendarDays };
const icon = computed(() => ICONS[props.name] ?? Text);
</script>

<template>
    <component :is="icon" aria-hidden="true" />
</template>
