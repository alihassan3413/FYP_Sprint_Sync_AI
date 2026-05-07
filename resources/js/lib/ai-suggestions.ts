/**
 * AI prompt suggestions, organized by page context.
 *
 * As the product matures, generate these dynamically from real workspace
 * state (e.g. "Plan a sprint for {nextMonday}", "Triage the {n} blocked tasks").
 *
 * Keep them SHORT — long chips wrap awkwardly in the dock's 503px width.
 */

export type AIContext = 'dashboard' | 'teams' | 'tasks' | 'sprints' | 'projects';

const suggestions: Record<AIContext, string[]> = {
  dashboard: [
    "Plan this week's sprint",
    "Who's overloaded?",
  ],
  teams: [
    'Invite a Team Member',
    'Member Details',
  ],
  tasks: [
    'Triage blocked tasks',
    'What should I work on next?',
  ],
  sprints: [
    'Health-check this sprint',
    'Generate a retro summary',
  ],
  projects: [
    'Identify at-risk projects',
    'Draft a stakeholder update',
  ],
};

export function getSuggestions(context: AIContext): string[] {
  return suggestions[context] ?? [];
}
