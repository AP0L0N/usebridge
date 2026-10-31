<?php

namespace App\Mcp\Tools;

use App\Models\Label;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_labels')]
#[Description('Labels of your workspace with their ticket counts. Unknown label names on ticket writes are created grey.')]
#[IsReadOnly]
class ListLabels extends BridgeTool
{
    protected function run(Request $request): array
    {
        return ['data' => Label::query()->withCount('tickets')->orderBy('name')->get()
            ->map(fn (Label $l) => [...$l->toView(), 'ticket_count' => $l->tickets_count])->all()];
    }
}
