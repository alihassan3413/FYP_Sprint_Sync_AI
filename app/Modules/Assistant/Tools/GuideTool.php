<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Contracts\AssistantTool;
use App\Modules\Assistant\Support\GuideAudience;
use App\Modules\Assistant\Support\GuideLibrary;
use App\Modules\Assistant\Support\ToolContext;

/**
 * Teaches a person how to use SprintSync, tailored to what their role actually
 * permits. Read-only and available to everyone, including someone who has not
 * created a workspace yet.
 */
final class GuideTool implements AssistantTool
{
    /** Below this, a single match is not certain enough to teach without asking. */
    private const PICK_ONE = 60;

    private const MAX_CANDIDATES = 4;

    public function name(): string
    {
        return 'get_guide';
    }

    public function description(): string
    {
        return 'Returns SprintSync training material, filtered to what this user is actually allowed to do. '
            .'Call this whenever the user asks how to do something in SprintSync, what a feature means, what they '
            .'are allowed to do, how to get started, or asks to be taught, trained, shown around or walked through '
            .'the product. Call it with no topic to get their whole curriculum as a table of contents, or pass '
            .'topic with the user\'s own words ("how do sprints work", "invite a client") to get that lesson with '
            .'its steps. Prefer this over answering from memory: it is the only accurate description of what this '
            .'product does, and it never teaches a feature the user\'s role would refuse them.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'description' => 'What the user wants to learn, in their own words. Omit to return the full curriculum outline.',
                    'maxLength' => 120,
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function authorize(ToolContext $context): bool
    {
        return $context->user->exists;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ToolContext $context): array
    {
        $audience = GuideAudience::for($context);
        $topic = isset($args['topic']) ? trim((string) $args['topic']) : '';

        if ($topic === '') {
            return $this->curriculum($audience, $context);
        }

        /* The model may echo back a slug we handed it in a previous turn. */
        if (GuideLibrary::has($topic) && in_array($topic, GuideLibrary::slugsFor($audience), true)) {
            return $this->teach($topic, $audience);
        }

        $matches = GuideLibrary::search($topic, $audience);

        if ($matches === []) {
            return [
                'success' => true,
                'found' => false,
                'message' => 'No lesson covers that. Show the user the curriculum below and let them pick, '
                    .'and do not invent a feature that is not in it.',
            ] + $this->curriculum($audience, $context);
        }

        /*
         * The library already breaks near-ties towards the more foundational
         * lesson, so a solid match is safe to teach outright. Only a weak best
         * match is worth stopping to ask about.
         */
        if (count($matches) === 1 || $matches[0]['score'] >= self::PICK_ONE) {
            return $this->teach($matches[0]['item'], $audience);
        }

        return [
            'success' => true,
            'found' => true,
            'needs_disambiguation' => true,
            'message' => 'Several lessons could match. Ask the user which one they meant — do not pick for them.',
            'candidates' => array_map(
                fn (array $match) => [
                    'topic' => $match['item'],
                    'title' => GuideLibrary::titleOf($match['item']),
                ],
                array_slice($matches, 0, self::MAX_CANDIDATES),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teach(string $slug, GuideAudience $audience): array
    {
        return [
            'success' => true,
            'found' => true,
            'lesson' => GuideLibrary::lesson($slug, $audience),
            'message' => 'Walk the user through these steps in order, in your own words but without adding '
                .'features that are not listed. Keep it to the steps that answer what they asked, then offer '
                .'the next topic.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function curriculum(GuideAudience $audience, ToolContext $context): array
    {
        $slugs = GuideLibrary::slugsFor($audience);

        return [
            'success' => true,
            'found' => true,
            'learner' => $audience->describe($context->workspace),
            'total_lessons' => count($slugs),
            'start_here' => $slugs[0] ?? null,
            'curriculum' => GuideLibrary::curriculumFor($audience),
            'message' => 'This is everything this user is allowed to be taught. Give them the stages and a '
                .'one-line summary each, then offer to start at start_here. Do not dump every step of every '
                .'lesson at once — teach one topic at a time, calling this tool again with that topic.',
        ];
    }
}
