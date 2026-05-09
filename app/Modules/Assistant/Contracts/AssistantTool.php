<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use App\Models\User;

/**
 * Every tool the AI can call must implement this contract.
 *
 * A "tool" is a function the LLM can request. Examples: create_workspace,
 * invite_teammate, list_projects. Each one is essentially an adapter that
 * exposes one of your existing Action classes to the AI.
 *
 * Why this matters in production:
 * - Single source of truth: the same business logic runs whether triggered
 *   by a UI button or by the AI. No duplicate code paths to keep in sync.
 * - Permission safety: every tool MUST authorize before executing.
 * - Schema-driven: the parameters() method is JSON Schema, which OpenAI
 *   and Gemini both consume natively for function calling.
 */
interface AssistantTool
{
    /**
     * Snake_case identifier used by the LLM to invoke this tool.
     * Must be unique across the registry.
     *
     * Production rule: never rename a tool name once shipped. The LLM has
     * been trained/prompted to use these names; renaming breaks ongoing
     * conversations mid-flight.
     */
    public function name(): string;

    /**
     * Plain-English description the LLM uses to decide WHEN to call this.
     * Write this carefully — it's a prompt. Bad descriptions cause the AI
     * to call the wrong tool or refuse to call any tool at all.
     *
     * Good: "Creates a new workspace. Use when the user wants to start a
     *        new team space, project area, or organizational unit."
     * Bad:  "Creates workspace"
     */
    public function description(): string;

    /**
     * JSON Schema describing the arguments. Returned object should have
     * 'type', 'properties', and 'required' keys at minimum.
     *
     * Keep parameters minimal — every optional parameter is another way
     * the LLM can hallucinate values. If the user didn't say a value,
     * the tool shouldn't ask the LLM to invent one.
     */
    public function parameters(): array;

    /**
     * Whether this tool needs explicit user confirmation in the UI before
     * executing. Rule of thumb: if it CREATES, MODIFIES, or DELETES
     * anything user-visible, return true. If it only READS data, false.
     *
     * Production safety: when in doubt, return true. A confirmation prompt
     * is annoying; an unauthorized destructive action is a support ticket.
     */
    public function requiresConfirmation(): bool;

    /**
     * Authorization check. Called BEFORE the tool is exposed to the LLM
     * for a given user, AND again before execution. Belt and suspenders.
     *
     * Use Laravel Gates/Policies here. Never inline the check.
     */
    public function authorize(User $user): bool;

    /**
     * The actual work. By the time we reach this method, args have been
     * validated against parameters() and the user has been authorized.
     *
     * Return shape should be JSON-serializable. The result is fed back
     * into the LLM as a 'tool' message so it can compose its final reply.
     *
     * Keep return payloads small (< 4KB ideally). Large payloads burn
     * tokens and slow down the response. If you must return a list, cap it.
     */
    public function execute(array $args, User $user): array;
}
