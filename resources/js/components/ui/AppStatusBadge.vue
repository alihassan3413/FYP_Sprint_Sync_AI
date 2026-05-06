<script setup lang="ts">
import { computed } from 'vue';

type Status = 'active' | 'away' | 'offline' | 'pending' | 'suspended' | string;

interface Props {
  status: Status;
}

const props = defineProps<Props>();

const config = computed(() => {
  const map: Record<string, { label: string; variant: 'success' | 'warning' | 'neutral' | 'danger'; pulse?: boolean }> = {
    active: { label: 'Active', variant: 'success', pulse: true },
    away: { label: 'Away', variant: 'neutral' },
    offline: { label: 'Offline', variant: 'neutral' },
    pending: { label: 'Pending', variant: 'warning' },
    suspended: { label: 'Suspended', variant: 'danger' },
  };
  return map[props.status.toLowerCase()] || { label: props.status, variant: 'neutral' as const };
});
</script>

<template>
  <AppBadge :variant="config.variant" :dot="true" :pulse="config.pulse" size="sm">
    {{ config.label }}
  </AppBadge>
</template>
