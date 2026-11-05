<?php

namespace App\Services\PowerSaver;

/**
 * Proposed Power Saver contract. `locked` is false until Captain agrees the comparison and what "stop" does.
 * Bridge stores a percent and does not apply it.
 *
 * Boundary (0 and 100 are stored values; null is the absence of a row):
 * - null: no threshold. Captain must not stop the subject for Power Saver.
 * - 0: a real threshold. Under the proposed >= comparison, any usage at or above 0% meets it.
 * - 100: a real threshold at the full ceiling. Under the proposed >= comparison, only usage at or above 100% meets it.
 * The ceiling, the time window, and whether ">=" is the comparison are not defined here.
 *
 * @phpstan-type StopOption array{id: string, status: string, summary: string}
 */
final class ThresholdSemantics
{
    public const MIN = 0;

    public const MAX = 100;

    public const COMPARISON = 'proposed_gte';

    /**
     * Options for what a stop would do. None is chosen. Archive/status is rejected so it cannot be mistaken for a power stop.
     *
     * @return list<StopOption>
     */
    public static function stopOptions(): array
    {
        return [
            [
                'id' => 'external_only',
                'status' => 'proposed',
                'summary' => 'Captain stops the work outside Bridge. Bridge only stores the percent.',
            ],
            [
                'id' => 'future_power_hold',
                'status' => 'not_built',
                'summary' => 'A later hold flag, separate from project status and archived_at, that Captain would set. Not in this change.',
            ],
            [
                'id' => 'archive_or_status',
                'status' => 'rejected',
                'summary' => 'Do not use archived or project status as a power stop. Those are the PM lifecycle.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        return [
            'status' => 'proposed',
            'locked' => false,
            'unit' => 'percent_of_usage_ceiling',
            'min' => self::MIN,
            'max' => self::MAX,
            'unset' => null,
            'stored_zero_means' => 'threshold',
            'stored_hundred_means' => 'full_ceiling',
            'null_means' => 'no_threshold',
            'comparison' => self::COMPARISON,
            'comparison_agreed' => false,
            'comparison_summary' => 'Proposed, not agreed: treat usage as reaching the threshold when its percent is greater than or equal to stop_percent. 0 then matches any usage at or above 0%. 100 matches only a full ceiling. null means do not stop.',
            'usage_ceiling_defined' => false,
            'project_usage_attribution' => false,
            'enforcement' => 'not_implemented',
            'mutates_subjects' => false,
            'summary' => 'A stop threshold is a whole number from 0 to 100, a percent of a usage ceiling Bridge does not define. Empty means no threshold. 0 and 100 are real thresholds, not empty. Bridge does not compare usage and does not stop anything.',
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
            'chosen_stop_option' => null,
            'stop_options' => self::stopOptions(),
            'excluded_routine_ids' => ['unmatched', 'other_est_tokens'],
            'summary' => 'Bridge stores these thresholds and does not stop anything. Projects have no enabled/disabled flag; archived and status are not a power stop. Routines are ids from usage files, not switches, and they are not linked to projects.',
        ];
    }
}
