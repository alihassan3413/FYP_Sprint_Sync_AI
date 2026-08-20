export interface SearchableCommand {
    name: string;
    label: string;
    description: string;
    category: string;
    keywords: string[];
}

/**
 * Words people wrap a request in that carry no signal about which action they
 * want. "I want to create a workspace" has to rank the same as "create
 * workspace", so these are dropped before scoring.
 */
const NOISE_WORDS = new Set([
    'a',
    'an',
    'and',
    'are',
    'can',
    'could',
    'do',
    'for',
    'from',
    'get',
    'how',
    'i',
    'in',
    'is',
    'it',
    'like',
    'me',
    'my',
    'need',
    'of',
    'on',
    'or',
    'our',
    'please',
    'that',
    'the',
    'this',
    'to',
    'want',
    'wanna',
    'we',
    'with',
    'would',
    'you',
]);

const WEIGHT_LABEL = 4;
const WEIGHT_KEYWORD = 3;
const WEIGHT_NAME = 2;
const WEIGHT_DESCRIPTION = 1;

export function tokenize(value: string): string[] {
    return value
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter((token) => token.length > 0);
}

export function meaningfulTokens(query: string): string[] {
    const tokens = tokenize(query);
    const meaningful = tokens.filter((token) => !NOISE_WORDS.has(token) && token.length > 1);

    return meaningful.length > 0 ? meaningful : tokens;
}

function fieldScore(token: string, haystack: string, weight: number): number {
    if (!haystack.includes(token)) {
        return 0;
    }

    return haystack.startsWith(token) ? weight + 1 : weight;
}

export function scoreCommand(command: SearchableCommand, tokens: string[]): number {
    const label = command.label.toLowerCase();
    const description = command.description.toLowerCase();
    const name = command.name.toLowerCase().replace(/_/g, ' ');
    const keywords = command.keywords.map((keyword) => keyword.toLowerCase());

    let matched = 0;
    let total = 0;

    for (const token of tokens) {
        const best = Math.max(
            fieldScore(token, label, WEIGHT_LABEL),
            fieldScore(token, name, WEIGHT_NAME),
            fieldScore(token, description, WEIGHT_DESCRIPTION),
            ...keywords.map((keyword) => fieldScore(token, keyword, WEIGHT_KEYWORD)),
        );

        if (best > 0) {
            matched += 1;
            total += best;
        }
    }

    if (matched === 0) {
        return 0;
    }

    // Answering more of what was typed outranks matching one common word well.
    return total * matched;
}

export function searchCommands<T extends SearchableCommand>(list: T[], query: string): T[] {
    const trimmed = query.trim();

    if (trimmed === '') {
        return list;
    }

    const tokens = meaningfulTokens(trimmed);

    return list
        .map((command) => ({ command, score: scoreCommand(command, tokens) }))
        .filter((entry) => entry.score > 0)
        .sort((a, b) => b.score - a.score)
        .map((entry) => entry.command);
}
