<?php

// First-project onboarding (GET /welcome, Figma "Onboarding" step 1–3) and its "done" view on the Portfolio: every
// visible string. Sent to the pages as the `copy` prop (OnboardingController, PortfolioController); :placeholders are
// filled on the page. The server's own validation messages for POST /projects and the column labels are under errors.
return [
    'title' => 'Welcome',
    'overline' => 'Step :n of 3',
    'heading' => 'Welcome to Bridge, :name',
    'subline' => 'Three quick steps to your first board. You can change everything later.',

    'stepper' => [
        'label' => 'Setup steps',
        'steps' => ['Project', 'Board', 'Connect your bot'],
        'current' => '(current step)',
        'done' => '(done)',
    ],

    'project' => [
        'title' => 'Create your first project',
        'sub' => 'A project holds one board and its tickets.',
        'name' => 'Project name',
        'name_placeholder' => 'e.g. Garden planner',
        'description' => 'Short description (optional)',
        'description_placeholder' => 'What is this project for?',
        'description_help' => 'Shown on the Portfolio card. One line is enough.',
        'lead' => 'Project lead',
        'lead_you' => 'You',
        'lead_help' => 'The lead sets the project status. An agent can take over once your bot is connected.',
        // Slug + ticket prefix follow the name; "Change" shows the two fields (and a taken one opens them).
        'address' => 'Board address :slug · ticket keys :key',
        'address_change' => 'Change',
        'address_hide' => 'Hide',
        'slug' => 'Slug',
        'slug_help' => 'The board\'s address: /projects/:slug. Unique across Bridge.',
        'prefix' => 'Ticket prefix',
        'prefix_help' => 'Ticket keys look like :key. Up to 8 letters or digits, unique across Bridge.',
        'submit' => 'Create project',
        'busy' => 'Creating…',
        'created' => ':name is created.',
        'created_body' => 'It has its own card on your Portfolio and its own board.',
        'continue' => 'Continue to the board',
        'off' => 'Creating projects here isn\'t switched on yet. You can already connect your bot (step 3).',
        'skip_to_bot' => 'Skip to step 3',
    ],

    'board' => [
        'title' => 'Set up its board',
        'sub' => 'Start with these columns or rename them. Tickets move left to right.',
        'list_label' => 'Board columns',
        'rename_label' => 'Column :n name (default :default)',
        'core' => 'Core column',
        'help' => 'Select a column name to rename it. Tickets your bot creates land in :first. When a bot needs your input, it moves the ticket to :waiting.',
        'no_project' => 'Create the project first (step 1) to rename its columns.',
        'back' => 'Back',
        'save' => 'Save board',
        'busy' => 'Saving…',
        'continue' => 'Continue',
    ],

    'bot' => [
        'tag' => 'MCP · optional',
        'title' => 'Connect your bot',
        'sub' => 'Optional. Let a bot read this board, move tickets and post status updates.',
        'steps' => [
            ['title' => 'Create a token', 'body' => 'In Account, API tokens & MCP. Pick read & write if the bot should move tickets.'],
            ['title' => 'Add an MCP connector in your bot', 'body' => 'Paste the server URL :url and your token as the auth header.'],
            ['title' => 'Ask your bot “list my Bridge boards”', 'body' => 'It should answer with :project.'],
        ],
        'your_projects' => 'your projects',
        'verify' => 'Verify your email first: tokens need a verified email. The link is in your inbox.',
        'skip' => 'Skip for now',
        'create_token' => 'Create a token',
        'later' => 'You can do this later in Account, API tokens & MCP.',
    ],

    'done' => [
        'ready' => ':project is ready. Open it to add your first tickets.',
        'connect' => 'Connect your bot so it can read this board and move tickets.',
        'connect_link' => 'Set up API tokens & MCP →',
    ],

    'errors' => [
        'name_required' => 'Give the project a name.',
        'slug_required' => 'Choose a slug for the board address.',
        'slug_regex' => 'Use lowercase letters, digits and single dashes (e.g. garden-planner).',
        'slug_taken' => 'This slug is already taken.',
        'prefix_required' => 'Choose a ticket prefix.',
        'prefix_regex' => 'Use 1–8 capital letters or digits, starting with a letter (e.g. GP).',
        'prefix_taken' => 'This ticket prefix is already taken.',
        'column_required' => 'Give every column a name.',
        'column_max' => 'Column names are at most :max characters.',
        'generic' => 'Something went wrong. Try again.',
    ],
];
