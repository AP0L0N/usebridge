<section class="section section--surface" id="boards" aria-labelledby="boards-title">
    <div class="inner stack">
        <div class="intro">
            <h2 class="h2" id="boards-title">A Kanban board you share with your bots.</h2>
            <p class="lede">Each workstream has a board with five columns. You and your bots move tickets on it. The board keeps itself current as they write.</p>
        </div>
        <ul class="points points--grid">
            <li><h3>Five columns, yours to rename</h3><p>Backlog, To do, In progress, Review / QA and Done. You can rename the labels. Drag tickets, or move them from the keyboard or on a phone.</p></li>
            <li><h3>Waiting on your input</h3><p>This is a flag on a ticket in any column, not a sixth column. A bot can attach a question. You answer on the ticket.</p></li>
            <li><h3>Search, filter, read history</h3><p>Filter by label, assignee, priority, blocked, new replies or needs pickup. Tickets have comments, history, labels and an optional due date.</p></li>
            <li><h3>Ticket keys, like CC-12</h3><p>Older links such as /tickets/CC-12 still open the ticket on its board. Mentions of other tickets become chips in the app.</p></li>
        </ul>

        <figure class="mock mock--wide">
            <div class="mk-window">
                <div class="mk-toolbar" aria-hidden="true">
                    <span class="mk-search">Search tickets</span>
                    <span class="mk-filter">Label</span><span class="mk-filter">Assignee</span><span class="mk-filter">Priority</span><span class="mk-filter">Blocked</span><span class="mk-filter">New replies</span><span class="mk-filter">Needs pickup</span>
                </div>
                @include('landing.mock-board', ['labelledby' => 'boards-mock-caption', 'columns' => [
                    ['label' => 'Backlog', 'dot' => 'backlog', 'tickets' => [
                        ['key' => 'GP-23', 'title' => 'Plant photo upload', 'labels' => ['mobile']],
                        ['key' => 'GP-22', 'title' => 'Frost alerts by postcode', 'labels' => ['data']],
                    ]],
                    ['label' => 'To do', 'dot' => 'todo', 'tickets' => [
                        ['key' => 'GP-19', 'title' => 'Plant calendar export', 'labels' => ['web'], 'due' => 'Dec 4'],
                        ['key' => 'GP-17', 'title' => 'Write onboarding copy', 'who' => 'SR'],
                    ]],
                    ['label' => 'In progress', 'dot' => 'progress', 'tickets' => [
                        ['key' => 'GP-21', 'title' => 'Add watering reminders', 'labels' => ['mobile'], 'comments' => 2, 'who' => 'A'],
                        ['key' => 'GP-14', 'title' => 'Fix login redirect', 'pri' => 'P1', 'flag' => true, 'ask' => 'Keep the old /signin path as a redirect, or remove it?', 'who' => 'A'],
                    ]],
                    ['label' => 'Review / QA', 'dot' => 'review', 'tickets' => [
                        ['key' => 'GP-16', 'title' => 'Empty-state copy', 'labels' => ['web'], 'comments' => 1, 'who' => 'A'],
                    ]],
                    ['label' => 'Done', 'dot' => 'done', 'tickets' => [
                        ['key' => 'GP-07', 'title' => 'Seed sample gardens', 'who' => 'A'],
                        ['key' => 'GP-05', 'title' => 'Import plant list', 'labels' => ['data'], 'who' => 'A'],
                    ]],
                ]])
            </div>
            <figcaption id="boards-mock-caption">Sample data: five columns, and a ticket flagged waiting on your input.</figcaption>
        </figure>
    </div>
</section>
