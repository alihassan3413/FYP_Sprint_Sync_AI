<script setup lang="ts">
import { computed } from 'vue';

interface AvatarItem {
  name?: string;
  email?: string;
  src?: string | null;
}

interface Props {
  users: AvatarItem[];
  max?: number;
  size?: 'xs' | 'sm' | 'md' | 'lg';
}

const props = withDefaults(defineProps<Props>(), {
  max: 4,
  size: 'sm',
});

const visible = computed(() => props.users.slice(0, props.max));
const overflow = computed(() => Math.max(0, props.users.length - props.max));

const ringSize = computed(() => ({
  xs: 'ring-[1.5px]',
  sm: 'ring-2',
  md: 'ring-2',
  lg: 'ring-[3px]',
}[props.size]));

const overflowSize = computed(() => ({
  xs: 'size-6 text-[10px]',
  sm: 'size-7 text-[11px]',
  md: 'size-8 text-xs',
  lg: 'size-10 text-sm',
}[props.size]));
</script>

<template>
  <div class="flex items-center -space-x-2">
    <div
      v-for="(user, i) in visible"
      :key="i"
      :class="[ringSize, 'rounded-full ring-background']"
    >
      <AppAvatar :name="user.name" :email="user.email" :src="user.src" :size="size" />
    </div>

    <span
      v-if="overflow > 0"
      :class="[
        overflowSize,
        ringSize,
        'inline-flex items-center justify-center rounded-full bg-muted font-medium text-muted-foreground ring-background',
      ]"
    >
      +{{ overflow }}
    </span>
  </div>
</template>
