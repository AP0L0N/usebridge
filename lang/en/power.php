<?php

// Power Saver validation. The page chrome lives in resources/js/lib/strings-power.ts; these are the server replies.
return [
    'errors' => [
        'percent' => 'Enter a whole number from 0 to 100, or leave it empty for no threshold.',
        'project_required' => 'Name the project.',
        'routine_required' => 'Name the agent and the routine.',
        'project_only' => 'A project threshold cannot include an agent or a routine.',
        'routine_only' => 'A routine threshold cannot include a project.',
        'unknown_project' => 'No project with this id is in this workspace.',
        'unknown_routine' => 'That routine is not in this workspace. The agent must exist here, or a usage file must already list the routine.',
        'excluded_routine' => 'That id is not a routine. Ad-hoc usage and other estimated tokens are not listed.',
        'agent_id' => 'The agent id must be 1–64 characters: letters, digits, "-" or "_".',
        'routine_id' => 'The routine id must be 1–120 characters.',
    ],
];
