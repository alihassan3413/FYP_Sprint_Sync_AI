import type { Notification, NotificationType } from '@/types/errors';
import { defineStore } from 'pinia';
import { ref } from 'vue';

const DEFAULT_DURATION: Record<NotificationType, number> = {
    success: 4000,
    info: 5000,
    warning: 6000,
    error: 7000,
};

export const useNotificationStore = defineStore('notification', () => {
    const notifications = ref<Notification[]>([]);

    function push(input: Omit<Notification, 'id'>): string {
        const id = crypto.randomUUID();
        const duration = input.duration ?? DEFAULT_DURATION[input.type];

        const notification: Notification = {
            id,
            duration,
            ...input,
        };

        notifications.value.push(notification);

        if (duration && duration > 0) {
            setTimeout(() => dismiss(id), duration);
        }

        return id;
    }

    function success(message: string, options: Partial<Omit<Notification, 'id' | 'type' | 'message'>> = {}): string {
        return push({ type: 'success', message, ...options });
    }

    function error(message: string, options: Partial<Omit<Notification, 'id' | 'type' | 'message'>> = {}): string {
        return push({ type: 'error', message, ...options });
    }

    function info(message: string, options: Partial<Omit<Notification, 'id' | 'type' | 'message'>> = {}): string {
        return push({ type: 'info', message, ...options });
    }

    function warning(message: string, options: Partial<Omit<Notification, 'id' | 'type' | 'message'>> = {}): string {
        return push({ type: 'warning', message, ...options });
    }

    function dismiss(id: string): void {
        notifications.value = notifications.value.filter((n) => n.id !== id);
    }

    function clear(): void {
        notifications.value = [];
    }

    return {
        notifications,
        push,
        success,
        error,
        info,
        warning,
        dismiss,
        clear,
    };
});
