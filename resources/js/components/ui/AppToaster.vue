<script setup lang="ts">
import { useNotificationStore } from '@/stores/notification.store'
import type { Notification, NotificationType } from '@/types/errors'
import { storeToRefs } from 'pinia'
import { computed } from 'vue'

const store = useNotificationStore()
const { notifications } = storeToRefs(store)

/** Style variant per notification type. */
const variants: Record<NotificationType, { accent: string; icon: string }> = {
  success: { accent: '#d4ff4f', icon: '✓' },
  error:   { accent: '#ff5f5f', icon: '✕' },
  warning: { accent: '#ffb84f', icon: '!' },
  info:    { accent: '#7fb8ff', icon: 'i' },
}

function variantFor(n: Notification) {
  return variants[n.type]
}

function handleActionClick(n: Notification) {
  n.action?.onClick()
  store.dismiss(n.id)
}

const reversed = computed(() => [...notifications.value].reverse())
</script>

<template>
  <Teleport to="body">
    <div
      class="pointer-events-none fixed top-4 right-4 z-[9999] flex w-full max-w-sm flex-col gap-3"
      aria-live="polite"
      aria-atomic="false"
    >
      <TransitionGroup
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-x-full opacity-0"
        enter-to-class="translate-x-0 opacity-100"
        leave-active-class="transition duration-200 ease-in absolute right-0"
        leave-from-class="translate-x-0 opacity-100"
        leave-to-class="translate-x-full opacity-0"
        move-class="transition duration-300 ease-out"
      >
        <div
          v-for="n in reversed"
          :key="n.id"
          class="pointer-events-auto relative w-full"
        >
          <!-- Offset shadow (Neo-Brutalism signature) -->
          <div
            class="absolute inset-0 translate-x-[5px] translate-y-[5px]"
            :style="{ backgroundColor: variantFor(n).accent }"
          ></div>

          <!-- Toast card -->
          <div
            class="relative flex items-start gap-3 border-2 border-black bg-white p-4"
            role="status"
          >
            <!-- Icon -->
            <div
              class="flex h-7 w-7 shrink-0 items-center justify-center border-2 border-black text-sm font-extrabold text-black"
              :style="{ backgroundColor: variantFor(n).accent }"
            >
              {{ variantFor(n).icon }}
            </div>

            <!-- Content -->
            <div class="min-w-0 flex-1">
              <p
                v-if="n.title"
                class="mb-1 text-sm font-extrabold leading-tight text-black"
              >
                {{ n.title }}
              </p>
              <p class="text-sm leading-snug text-black break-words">
                {{ n.message }}
              </p>

              <!-- Optional action button -->
              <button
                v-if="n.action"
                type="button"
                class="mt-2 inline-block border-2 border-black bg-black px-3 py-1 text-xs font-extrabold tracking-wide text-white hover:opacity-90"
                @click="handleActionClick(n)"
              >
                {{ n.action.label }}
              </button>

              <!-- Trace ID (only shown on errors, very small) -->
              <p
                v-if="n.type === 'error' && n.traceId"
                class="mt-2 font-mono text-[10px] text-gray-400"
              >
                ID: {{ n.traceId }}
              </p>
            </div>

            <!-- Dismiss -->
            <button
              type="button"
              class="shrink-0 text-gray-400 hover:text-black"
              aria-label="Dismiss"
              @click="store.dismiss(n.id)"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="3"
                stroke-linecap="square"
                stroke-linejoin="miter"
              >
                <line x1="18" y1="6" x2="6" y2="18" />
                <line x1="6" y1="6" x2="18" y2="18" />
              </svg>
            </button>
          </div>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>
