<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Services\Tickets\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Global labels (reused across projects). Unknown names on ticket writes are auto-created grey. */
class LabelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Label::query()->withCount('tickets')->orderBy('name')->get()
            ->map(fn (Label $l) => [...$l->toView(), 'ticket_count' => $l->tickets_count])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        Actor::fromApi($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:24'],
            'color' => ['nullable', Rule::in(Label::COLORS)],
        ]);
        $this->ensureUnique($data['name']);
        $label = Label::create(['id' => 'lb-'.Str::lower((string) Str::ulid()), 'name' => trim($data['name']), 'color' => $data['color'] ?? 'grey']);

        return response()->json(['data' => $label->toView()], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $label = Label::query()->find($id);
        abort_if(! $label, 404, "Unknown label '$id'.");
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:24'],
            'color' => ['sometimes', 'required', Rule::in(Label::COLORS)],
        ]);
        if (isset($data['name'])) {
            $this->ensureUnique($data['name'], $label->id);
            $data['name'] = trim($data['name']);
        }
        $label->update($data);

        return response()->json(['data' => $label->fresh()->toView()]);
    }

    private function ensureUnique(string $name, ?string $except = null): void
    {
        $exists = Label::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => "A label named '$name' already exists."]);
        }
    }
}
