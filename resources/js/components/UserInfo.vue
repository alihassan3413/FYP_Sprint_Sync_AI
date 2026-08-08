<script setup lang="ts">
import AppAvatar from '@/components/ui/AppAvatar.vue';
import type { User } from '@/types';

interface Props {
    user: User;
    showEmail?: boolean;
}

withDefaults(defineProps<Props>(), {
    showEmail: false,
});
</script>

<template>
    <AppAvatar :name="user.name" :email="user.email" :src="user.avatar_url" size="sm" />

    <div class="grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-medium">{{ user.name }}</span>
        <span v-if="showEmail" class="text-muted-foreground truncate text-xs">{{ user.email }}</span>
    </div>
</template>
