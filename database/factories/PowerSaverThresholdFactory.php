<?php

namespace Database\Factories;

use App\Models\PowerSaverThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PowerSaverThreshold>
 */
class PowerSaverThresholdFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => PowerSaverThreshold::TYPE_PROJECT,
            'project_id' => 'proj-northwind',
            'agent_id' => null,
            'routine_id' => null,
            'stop_percent' => 80,
            'updated_by_user_id' => null,
        ];
    }

    public function routine(string $agentId = 'agent-janice', string $routineId = 'support-inbox'): static
    {
        return $this->state(fn (): array => [
            'subject_type' => PowerSaverThreshold::TYPE_ROUTINE,
            'project_id' => null,
            'agent_id' => $agentId,
            'routine_id' => $routineId,
        ]);
    }

    public function percent(int $percent): static
    {
        return $this->state(fn (): array => ['stop_percent' => $percent]);
    }
}
