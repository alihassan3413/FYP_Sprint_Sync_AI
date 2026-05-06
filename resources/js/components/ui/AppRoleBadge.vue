<script setup lang="ts">
import { computed } from 'vue';
import {
  ShieldCheck,
  KeyRound,
  User,
  Eye,
  CreditCard,
  type LucideIcon,
} from 'lucide-vue-next';

type Role = 'owner' | 'admin' | 'member' | 'guest' | 'billing' | string;

interface Props {
  role: Role;
}

const props = defineProps<Props>();

type BadgeVariant = 'purple' | 'info' | 'neutral' | 'teal' | 'pink';

interface RoleConfig {
  label: string;
  variant: BadgeVariant;
  icon: LucideIcon;
}

const config = computed<RoleConfig>(() => {
  const map: Record<string, RoleConfig> = {
    owner: {
      label: 'Owner',
      variant: 'purple',
      icon: ShieldCheck,
    },
    admin: {
      label: 'Admin',
      variant: 'info',
      icon: KeyRound,
    },
    member: {
      label: 'Member',
      variant: 'neutral',
      icon: User,
    },
    guest: {
      label: 'Guest',
      variant: 'teal',
      icon: Eye,
    },
    billing: {
      label: 'Billing',
      variant: 'pink',
      icon: CreditCard,
    },
  };

  return (
    map[props.role.toLowerCase()] || {
      label: props.role,
      variant: 'neutral',
      icon: User,
    }
  );
});
</script>

<template>
  <AppBadge :variant="config.variant" size="sm">
    <template #icon>
      <component :is="config.icon" class="size-3" />
    </template>

    {{ config.label }}
  </AppBadge>
</template>
