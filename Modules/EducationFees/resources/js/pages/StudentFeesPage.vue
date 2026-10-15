<script setup>
import { useRoute } from 'vue-router';
import { ArrowLeft } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { t } from '@/lib/i18n';
import StudentFeesPanel from '../components/StudentFeesPanel.vue';

/**
 * One student's fees on their own page, opened from the fee counter. The
 * same panel is the fees tab of the student's page in Education.
 */
const route = useRoute();
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'fees-counter' }" :icon="ArrowLeft" class="mb-3">{{ t('education_fees.student.back') }}</AppButton>
        <StudentFeesPanel :student-id="String(route.params.id)" titled />
    </div>
</template>
