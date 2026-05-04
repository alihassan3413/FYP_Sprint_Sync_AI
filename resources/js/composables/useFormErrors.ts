import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

/**
 * Access form validation errors from Inertia's page.props.errors.
 *
 * Laravel's ValidationException populates page.props.errors automatically
 * via Inertia's middleware. This composable just makes it easier to read.
 *
 * ─── USAGE ────────────────────────────────────────────────────────
 *
 *   const { hasError, getError, errors } = useFormErrors()
 *
 *   <input :class="{ 'border-red-500': hasError('email') }" />
 *   <p v-if="hasError('email')" class="text-red-600">{{ getError('email') }}</p>
 */
export function useFormErrors() {
  const page = usePage<{ errors: Record<string, string> }>()

  const errors = computed(() => page.props.errors ?? {})
  const hasErrors = computed(() => Object.keys(errors.value).length > 0)

  function getError(field: string): string {
    return errors.value[field] ?? ''
  }

  function hasError(field: string): boolean {
    return field in errors.value && Boolean(errors.value[field])
  }

  function firstError(): string {
    const keys = Object.keys(errors.value)
    return keys.length > 0 ? errors.value[keys[0]] : ''
  }

  return {
    errors,
    hasErrors,
    getError,
    hasError,
    firstError,
  }
}
