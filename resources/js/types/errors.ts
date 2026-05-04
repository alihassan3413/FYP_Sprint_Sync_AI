export interface AppError {
  code: string
  message: string
  fields?: Record<string, string[]> | null
  meta: Record<string, unknown> & { trace_id?: string }
}

export interface ApiErrorResponse {
  success: false
  error: {
    code: string
    message: string
    fields: Record<string, string[]> | null
    meta: Record<string, unknown> & { trace_id?: string }
  }
}

export interface FlashError {
  code: string
  message: string
  meta: Record<string, unknown> & { trace_id?: string }
}

export type NotificationType = 'success' | 'error' | 'info' | 'warning'

export interface Notification {
  id: string
  type: NotificationType
  message: string
  title?: string
  code?: string
  traceId?: string
  duration?: number
  action?: {
    label: string
    onClick: () => void
  }
}
