import { useEcho } from '@laravel/echo-vue';

export interface TaskStatusUpdatedPayload {
    task_id: number;
    project_id: number;
    board_column_id: number;
    updated_at: string;
}

/**
 * Subscribes to a project's private board channel for the lifetime of the
 * calling component. Realtime is progressive enhancement: when Echo is not
 * configured or the socket is unreachable, the board keeps working over HTTP.
 */
export function useProjectTaskStream(projectId: number, onStatusUpdated: (payload: TaskStatusUpdatedPayload) => void) {
    if (!import.meta.env.VITE_REVERB_APP_KEY) {
        return;
    }

    try {
        useEcho<TaskStatusUpdatedPayload>(`project.${projectId}`, '.task.status-updated', onStatusUpdated);
    } catch {
        /* realtime unavailable — the board continues over HTTP */
    }
}
