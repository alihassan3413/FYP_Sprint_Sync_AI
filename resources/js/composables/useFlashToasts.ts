import { router, usePage } from '@inertiajs/vue3'
import { watch } from 'vue'
import { useNotificationStore } from '@/stores/notification.store'
import { handleError } from '@/lib/errors/handleError'
import type { FlashError } from '@/types/errors'

/**
 * Bridges Inertia flash messages to the toast system.
 *
 * Backend sends:
 *   back()->with('success', 'Workspace created.')
 *   back()->with('error', ['code' => '...', 'message' => '...', 'meta' => [...]])
 *   back()->with('info', 'Some info.')
 *
 * This composable watches `page.props.flash` and converts them to toasts.
 * Call it ONCE in your root component (after Pinia is mounted).
 *
 * It also listens for Inertia router exceptions (network errors, 500s on
 * Inertia requests) and routes them through handleError too.
 */
export function useFlashToasts(): void {
  const page = usePage<{
    flash?: {
      success?: string | null
      error?: FlashError | string | null
      info?: string | null
      warning?: string | null
    }
  }>()

  const notify = useNotificationStore()

  // Watch flash messages on every page change.
  // immediate:true catches the very first page load too.
  watch(
    () => page.props.flash,
    (flash) => {
      if (!flash) return

      if (flash.success) {
        notify.success(flash.success)
      }

      if (flash.info) {
        notify.info(flash.info)
      }

      if (flash.warning) {
        notify.warning(flash.warning)
      }

      if (flash.error) {
        // Errors flow through handleError so code-based handlers fire
        // (session expired → redirect, limit reached → action toast, etc.)
        handleError(flash.error)
      }
    },
    { deep: true, immediate: true }
  )

  // Catch Inertia-level errors (network failures, server crashes during navigation)
  router.on('exception', (event) => {
    handleError(event.detail.exception)
  })
}
