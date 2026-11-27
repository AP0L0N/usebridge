<section class="section" id="workstreams" aria-labelledby="workstreams-title">
    <div class="inner">
        <div class="feature feature--flip">
            <div class="col">
                <h2 class="h2" id="workstreams-title">Split a project into workstreams.</h2>
                <p class="lede">A workstream is a named board inside a project. Every project starts with one called General. Opening a project from the Portfolio takes you to its default workstream.</p>
                <ul class="points">
                    <li><h3>You name the boards</h3><p>Add, rename, reorder or set a default. Delete a workstream and you move its tickets first. Tickets are never deleted with the workstream.</p></li>
                    <li><h3>Bots file the tickets</h3><p>Agents can list workstreams and put a ticket into one by name or id. If they use a new name, Bridge creates that workstream.</p></li>
                    <li><h3>See what changed</h3><p>Unread badges show ticket changes in a workstream since you last opened its board.</p></li>
                </ul>
            </div>

            {{-- Static mock of the Portfolio Finder: projects, then the selected project's workstreams with unread badges. --}}
            <figure class="mock">
                <div class="mk-window mk-finder">
                    <div class="mk-fcol">
                        <p class="mk-fhead">Projects</p>
                        <ul>
                            <li class="on"><span>Garden planner</span><i class="mk-unread"></i></li>
                            <li><span>Client site redesign</span><i class="mk-unread"></i></li>
                            <li><span>Recipe box app</span></li>
                        </ul>
                    </div>
                    <div class="mk-fcol">
                        <p class="mk-fhead">Workstreams</p>
                        <ul>
                            <li class="on"><span><b>Mobile app</b><small>9 tickets</small></span><span class="mk-badge">3</span></li>
                            <li><span><b>Website</b><small>4 tickets</small></span><span class="mk-badge">1</span></li>
                            <li><span><b>Plant data</b><small>5 tickets</small></span></li>
                            <li><span><b>General <em class="mk-chip">Default</em></b><small>6 tickets</small></span></li>
                        </ul>
                    </div>
                </div>
                <figcaption>Sample data: workstreams inside a project, with unread badges.</figcaption>
            </figure>
        </div>
    </div>
</section>
