<?php

namespace App\Http\Requests;

use App\Models\PowerSaverThreshold;
use App\Models\User;
use App\Rules\WholePercent;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One or more Power Saver thresholds. Shared by the session page and PATCH /api/power-saver.
 * A null stop_percent clears the stored threshold. 0 and 100 are kept.
 */
class UpdatePowerSaverThresholds extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = app(CurrentWorkspace::class)->get();

        return $user instanceof User && $workspace !== null && $workspace->hasMember($user);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'thresholds' => ['required', 'array', 'min:1', 'max:100'],
            'thresholds.*' => ['array'],
            'thresholds.*.subject_type' => ['required', 'string', Rule::in([
                PowerSaverThreshold::TYPE_PROJECT,
                PowerSaverThreshold::TYPE_ROUTINE,
            ])],
            'thresholds.*.project_id' => ['nullable', 'string', 'max:255'],
            'thresholds.*.agent_id' => ['nullable', 'string', 'max:64'],
            'thresholds.*.routine_id' => ['nullable', 'string', 'max:120'],
            'thresholds.*.stop_percent' => ['present', 'nullable', new WholePercent],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('thresholds', []);
            if (! is_array($items)) {
                return;
            }
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $this->checkItem($validator, (int) $index, $item);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function checkItem(Validator $validator, int $index, array $item): void
    {
        $type = $item['subject_type'] ?? null;
        if ($type === PowerSaverThreshold::TYPE_PROJECT) {
            if (! is_string($item['project_id'] ?? null) || $item['project_id'] === '') {
                $validator->errors()->add("thresholds.$index.project_id", __('power.errors.project_required'));
            }
            if ($this->filledValue($item, 'agent_id') || $this->filledValue($item, 'routine_id')) {
                $validator->errors()->add("thresholds.$index.agent_id", __('power.errors.project_only'));
            }

            return;
        }

        if ($type !== PowerSaverThreshold::TYPE_ROUTINE) {
            return;
        }

        if ($this->filledValue($item, 'project_id')) {
            $validator->errors()->add("thresholds.$index.project_id", __('power.errors.routine_only'));
        }
        $agentId = $item['agent_id'] ?? null;
        $routineId = $item['routine_id'] ?? null;
        if (! is_string($agentId) || $agentId === '' || ! is_string($routineId) || $routineId === '') {
            $validator->errors()->add("thresholds.$index.routine_id", __('power.errors.routine_required'));

            return;
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $agentId) !== 1) {
            $validator->errors()->add("thresholds.$index.agent_id", __('power.errors.agent_id'));
        }
        if (mb_strlen($routineId) > 120) {
            $validator->errors()->add("thresholds.$index.routine_id", __('power.errors.routine_id'));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function filledValue(array $item, string $key): bool
    {
        return array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '';
    }
}
