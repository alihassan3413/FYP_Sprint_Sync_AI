import { useNotificationStore } from '@/stores/notification.store'
import type { AppError, ApiErrorResponse, FlashError } from '@/types/errors'
import { getCodeHandler } from './errorCodeHandlers'

/**
 * The single entry point for all errors in the app.
 *
 * Anything that goes wrong — Inertia flash error, axios rejection,
 * programmatic throw — should route through this function.
 *
 * Flow:
 *   1. Parse the input into a normalized AppError
 *   2. Look up a code-specific handler (errorCodeHandlers.ts)
 *   3. If a handler exists and returns true, stop
 *   4. Otherwise, fire a default error toast
 */
export function handleError(input: unknown): void {
  const notify = useNotificationStore()
  const error = parseError(input)

  // Code-specific handler takes precedence
  const handler = getCodeHandler(error.code)
  if (handler && handler(error, notify)) {
    return
  }

  // Default: show a toast
  notify.error(error.message, {
    code: error.code,
    traceId: error.meta.trace_id,
  })
}

/**
 * Convert anything into our AppError shape.
 * Supports:
 *   - Inertia FlashError ({ code, message, meta })
 *   - Axios error with ApiErrorResponse body
 *   - Plain Error / string / unknown
 */
export function parseError(input: unknown): AppError {
  // Inertia flash error — already in our shape
  if (isFlashError(input)) {
    return {
      code: input.code,
      message: input.message,
      meta: input.meta ?? {},
    }
  }

  // Axios error — extract from response.data
  if (isAxiosError(input)) {
    const data = input.response?.data
    if (isApiErrorResponse(data)) {
      return {
        code: data.error.code,
        message: data.error.message,
        fields: data.error.fields,
        meta: data.error.meta ?? {},
      }
    }
    // Axios error without our contract (e.g. network failure)
    return {
      code: 'NETWORK_ERROR',
      message: 'Unable to reach the server. Please check your connection.',
      meta: {},
    }
  }

  // Plain Error
  if (input instanceof Error) {
    return {
      code: 'INTERNAL_ERROR',
      message: input.message || 'Something went wrong.',
      meta: {},
    }
  }

  // String message
  if (typeof input === 'string') {
    return {
      code: 'INTERNAL_ERROR',
      message: input,
      meta: {},
    }
  }

  // Unknown — last-resort fallback
  return {
    code: 'INTERNAL_ERROR',
    message: 'An unexpected error occurred.',
    meta: {},
  }
}

// ─── Type guards ────────────────────────────────────────────────────

function isFlashError(value: unknown): value is FlashError {
  return (
    typeof value === 'object' &&
    value !== null &&
    'code' in value &&
    'message' in value &&
    typeof (value as FlashError).code === 'string'
  )
}

function isApiErrorResponse(value: unknown): value is ApiErrorResponse {
  return (
    typeof value === 'object' &&
    value !== null &&
    'success' in value &&
    (value as ApiErrorResponse).success === false &&
    'error' in value
  )
}

function isAxiosError(value: unknown): value is { response?: { data?: unknown } } {
  return (
    typeof value === 'object' &&
    value !== null &&
    'response' in value
  )
}
