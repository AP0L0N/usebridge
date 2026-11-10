<?php

namespace App\Services\PowerSaver;

/**
 * Locked Power Saver contract (Captain, 5 Nov 2026). Bridge stores percents and identities.
 * It does not compare usage, and it does not pause or resume anything.
 *
 * Ceiling: the shared weekly Ultra usage-limit percentage (one limit shared across the plan's apps).
 * Not a Grok-Bot-only percentage. Not additional credits.
 *
 * Compare current_usage >= stop_percent. Resume only when current_usage < stop_percent.
 * A weekly reset lowers usage and therefore resumes work. Bridge does not send that command.
 * - 0: stop at or above 0% (immediately, including zero usage).
 * - 100: stop only when the shared weekly limit is fully used.
 * - null: never stop.
 *
 * Project usage is an approximation. Bridge estimates are per agent, not per-project actuals.
 * The map is projects.owner_agent_id (the PM). One agent who is PM of several projects contributes
 * that agent's whole estimated usage to each of those projects. Do not split it.
 * A routine uses its own reported by_routine usage. No observation yet means current_usage 0.
 *
 * Archived project: the effective stop_percent is 0 (stop at any usage) until it is unarchived.
 * The saved threshold is kept as it is and applies again after unarchive. Project status is not a stop.
 *
 * @phpstan-type StopOption array{id: string, status: string, summary: string}
 */
final class ThresholdSemantics
{
    public const MIN = 0;

    public const MAX = 100;

    public const COMPARISON = 'gte';

    public const RESUME = 'strict_lt';

    public const CEILING = 'shared_weekly_ultra_usage_limit';

    public const ENFORCEMENT = 'external_only';

    public const ARCHIVED_STOP_PERCENT = 0;

    /**
     * The percent enforcement must use. An archived project stops at 0. Otherwise the saved percent (null = never stop).
     * The saved percent itself is never rewritten.
     */
    public static function effectiveStopPercent(?int $savedPercent, bool $archived): ?int
    {
        return $archived ? self::ARCHIVED_STOP_PERCENT : $savedPercent;
    }

    /**
     * @return list<StopOption>
     */
    public static function stopOptions(): array
    {
        return [
            [
                'id' => self::ENFORCEMENT,
                'status' => 'locked',
                'summary' => 'Bridge stores and exposes thresholds only. Each agent pauses and resumes its own routines outside Bridge.',
            ],
            [
                'id' => 'enabled_flag',
                'status' => 'rejected',
                'summary' => 'Do not add an enabled or disabled flag. Bridge has no power switch.',
            ],
            [
                'id' => 'archive_or_status',
                'status' => 'archive_only',
                'summary' => 'An archived project reads as stop_percent 0, so it stops at any usage until it is unarchived. Its saved threshold is kept and applies again after unarchive. Project status (smooth, waiting) is not a stop.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'status' => 'locked',
            'locked' => true,
            'unit' => 'percent_of_shared_weekly_ultra_usage_limit',
            'ceiling' => self::CEILING,
            'ceiling_scope' => 'shared_across_plan_apps',
            'ceiling_not' => ['grok_bot_only_percentage', 'additional_credits'],
            'min' => self::MIN,
            'max' => self::MAX,
            'unset' => null,
            'stored_zero_means' => 'stop_at_or_above_zero',
            'stored_hundred_means' => 'stop_only_when_fully_used',
            'null_means' => 'never_stops',
            'comparison' => self::COMPARISON,
            'comparison_agreed' => true,
            'comparison_rule' => 'current_usage >= stop_percent',
            'resume' => self::RESUME,
            'resume_rule' => 'current_usage < stop_percent',
            'weekly_reset_reenables' => true,
            'usage_ceiling_defined' => true,
            'bridge_computes_current_usage' => false,
            'project_usage_attribution' => 'approximate_agent_total_not_split',
            'project_usage' => [
                'quality' => 'approximate',
                'basis' => 'agent_total_estimated_usage',
                'not' => 'per_project_actuals',
                'mapping' => 'projects.owner_agent_id',
                'mapping_name' => 'pm',
                'same_agent_many_projects' => 'apply_agent_total_to_each_project',
                'split_usage' => false,
            ],
            'routine_usage' => [
                'signal' => 'own_routine_reported_usage',
                'source' => 'usage_days.payload.agents[].by_routine',
                'unobserved_current_usage' => 0,
            ],
            'project_agent_id' => [
                'field' => 'agent_id',
                'source' => 'projects.owner_agent_id',
                'cardinality' => 'at_most_one',
                'null_when' => 'no_pm',
            ],
            'project_agent_ids' => [
                'field' => 'agent_ids',
                'cardinality' => 'zero_or_one',
                'compat' => 'agent_id is the only element, or the list is empty when agent_id is null',
            ],
            'enforcement' => self::ENFORCEMENT,
            'enforcement_actor' => 'each_agent_pauses_and_resumes_its_own_routines',
            'mutates_subjects' => false,
            'archived_stop_percent' => self::ARCHIVED_STOP_PERCENT,
            'archived_project' => [
                'stop_percent' => 'effective',
                'effective_stop_percent' => self::ARCHIVED_STOP_PERCENT,
                'saved_field' => 'saved_stop_percent',
                'saved_threshold_rewritten' => false,
                'unarchive_restores' => 'saved_stop_percent',
                'listed_in_api' => true,
                'listed_on_page' => false,
            ],
            'project_status_is_stop' => false,
            'comparison_summary' => 'Stop when current usage is greater than or equal to the threshold. Turn work back on only when current usage is strictly below it. The weekly reset lowers usage, so it turns work back on by itself. Each agent pauses and resumes its own routines. Bridge does not.',
            'summary' => 'A threshold is a whole number from 0 to 100: a percent of the shared weekly Ultra usage limit, the plan limit shared across apps. It is not a Grok-Bot-only percentage and it is not additional credits. Empty means never stop. 0 stops at or above 0%. 100 stops only when that limit is fully used. An archived project counts as 0 until it is unarchived, then its saved number applies again. Bridge stores the number and does not stop anything.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function gaps(): array
    {
        return [
            'project_enabled_state' => false,
            'routine_enabled_state' => false,
            'routine_registry' => false,
            'bridge_can_pause_routines' => false,
            'chosen_stop_option' => self::ENFORCEMENT,
            'stop_options' => self::stopOptions(),
            'excluded_routine_ids' => ['unmatched', 'other_est_tokens'],
            'project_usage_is_approximate' => true,
            'summary' => 'Project usage is an approximation. Bridge estimates follow the agent, not a measured per-project total. A project uses its PM (owner_agent_id). When one agent is PM of several projects, that agent’s whole estimated usage counts for each project and is not split. A routine uses its own reported usage. A routine with no usage yet counts as 0%. An archived project counts as a 0% threshold, so it stays stopped until it is unarchived. Its saved threshold is kept. Project status is not a stop, and there is no enabled flag. Each agent pauses and resumes its own routines outside Bridge.',
        ];
    }
}
