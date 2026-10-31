<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CommentTicket;
use App\Mcp\Tools\CreateTicket;
use App\Mcp\Tools\GetBoard;
use App\Mcp\Tools\GetTicket;
use App\Mcp\Tools\ListAgents;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListProjects;
use App\Mcp\Tools\ListTickets;
use App\Mcp\Tools\MoveTicket;
use App\Mcp\Tools\PostStatus;
use App\Mcp\Tools\SetProjectStatus;
use App\Mcp\Tools\UpdateTicket;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/** POST /mcp (routes/ai.php): the token's workspace's boards, tickets and statuses (docs/MULTI-USER-PLAN.md §6). */
#[Name('Bridge')]
#[Version('1.0.0')]
#[Instructions('Bridge project boards for your workspace. Read with list_projects, get_board, list_tickets, get_ticket, list_agents, list_labels. Writes (create_ticket, update_ticket, move_ticket, comment_ticket, post_status, set_project_status) act as your agent: a per-agent token acts as that agent, a shared token needs the `actor` argument (an agent id from list_agents). Projects are addressed by id or slug, tickets by key (e.g. CA-12).')]
class BridgeServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        ListProjects::class,
        GetBoard::class,
        ListTickets::class,
        GetTicket::class,
        ListAgents::class,
        ListLabels::class,
        CreateTicket::class,
        UpdateTicket::class,
        MoveTicket::class,
        CommentTicket::class,
        PostStatus::class,
        SetProjectStatus::class,
    ];

    protected array $capabilities = [
        self::CAPABILITY_TOOLS => ['listChanged' => false],
    ];
}
