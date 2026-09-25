<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { Hammer } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { t } from '@/lib/i18n';

/**
 * Menu entries of business modules land here until each module ships its
 * own screens in its phase. The module is on (the menu only lists enabled ones).
 */
const route = useRoute();
const module = computed(() => route.params.module);
</script>

<template>
    <div class="card mt-6">
        <EmptyState :icon="Hammer" :title="t('modules.app.title')" :text="t('modules.app.text', { module })">
            <AppButton to="/modules">{{ t('modules.app.back') }}</AppButton>
        </EmptyState>
    </div>
</template>
