/**
 * Project health, as returned by EvaluateProjectHealth.
 *
 * Every number here is computed server-side. The UI decides how to show a
 * verdict; it never decides what the verdict is.
 */
export type HealthVerdict = 'no_data' | 'healthy' | 'watch' | 'at_risk' | 'critical';

export type FindingSeverity = 'critical' | 'warning' | 'note';

export interface HealthFinding {
    code: string;
    severity: FindingSeverity;
    headline: string;
    detail: string;
    suggestion: string | null;
}

export interface WorkloadEntry {
    user_id: number | null;
    name: string;
    open_tasks: number;
    overdue_tasks: number;
    completed_tasks: number;
    share_percentage: number;
}

export interface ProjectHealth {
    project_id: number;
    project_name: string;
    verdict: HealthVerdict;
    verdict_label: string;
    total_tasks: number;
    completed_tasks: number;
    open_tasks: number;
    completion_percentage: number;
    overdue_tasks: number;
    unassigned_open_tasks: number;
    stale_open_tasks: number;
    people_with_open_work: number;
    busiest_share_percentage: number;
    active_sprint_name: string | null;
    active_sprint_health: string | null;
    signals: HealthFinding[];
    workload: WorkloadEntry[];
}

/** Badge colours per verdict. Lime for good, amber for watch, rose for trouble. */
export const verdictStyles: Record<HealthVerdict, string> = {
    no_data: 'bg-muted text-muted-foreground',
    healthy: 'bg-lime-400/20 text-lime-700 dark:text-lime-300',
    watch: 'bg-amber-400/20 text-amber-700 dark:text-amber-300',
    at_risk: 'bg-orange-500/20 text-orange-700 dark:text-orange-300',
    critical: 'bg-rose-500/20 text-rose-700 dark:text-rose-300',
};

export const severityStyles: Record<FindingSeverity, string> = {
    critical: 'border-rose-500/40 bg-rose-500/[0.06]',
    warning: 'border-amber-500/40 bg-amber-500/[0.06]',
    note: 'border-border bg-muted/30',
};

export const severityDot: Record<FindingSeverity, string> = {
    critical: 'bg-rose-500',
    warning: 'bg-amber-500',
    note: 'bg-muted-foreground/40',
};

/** The bar colour for one person's share of the open work. */
export function shareTone(entry: WorkloadEntry, busiest: boolean): string {
    if (entry.user_id === null) return 'bg-muted-foreground/25';
    if (busiest && entry.share_percentage >= 70) return 'bg-rose-500';
    if (busiest && entry.share_percentage >= 50) return 'bg-amber-500';

    return 'bg-indigo-500';
}

/** Findings worth showing first: critical, then warnings, then notes. */
export function rankFindings(findings: HealthFinding[]): HealthFinding[] {
    const weight: Record<FindingSeverity, number> = { critical: 0, warning: 1, note: 2 };

    return [...findings].sort((a, b) => weight[a.severity] - weight[b.severity]);
}
