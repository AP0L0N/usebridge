<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Question;
use App\Services\QuestionAnswer;
use App\Support\Workspaces\WorkspaceRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Bridge r4 Questions (for the workspace owner) API (Sanctum, same style as next-steps). */
class QuestionController extends Controller
{
    /** Filters: project_id, state=open|answered|closed|all (default all, so agents can read answers). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['nullable', 'string'],
            'state' => ['nullable', Rule::in([...Question::STATES, 'all'])],
        ]);
        $q = Question::query()->orderBy('project_id')->orderBy('created_at')->orderBy('id');
        if (! empty($data['project_id'])) {
            $q->where('project_id', $data['project_id']);
        }
        $state = $data['state'] ?? 'all';
        if ($state !== 'all') {
            $q->where('state', $state);
        }

        return response()->json(['data' => $q->get()->map->toView()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'string', WorkspaceRule::exists('projects', 'id')],
            ...self::createRules(),
        ]);

        return $this->create($data);
    }

    /** Alias POST /api/projects/{project}/questions; {project} is the project id or its slug. */
    public function storeForProject(Request $request, string $project): JsonResponse
    {
        $p = Project::query()->find($project) ?? Project::query()->where('slug', $project)->firstOrFail();
        $data = $request->validate(self::createRules());

        return $this->create(['project_id' => $p->id, ...$data]);
    }

    /** @return array<string, array<int, mixed>> */
    private static function createRules(): array
    {
        return [
            'text' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::in(Question::TYPES)],
            'asked_by' => ['required', 'string', 'max:120'],
            'context' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function create(array $data): JsonResponse
    {
        $question = Question::create([
            ...$data,
            'id' => 'q-'.Str::lower((string) Str::ulid()),
            'state' => 'open',
        ]);

        return response()->json(['data' => $question->fresh()->toView()], 201);
    }

    /**
     * PATCH any of text, context, type, asked_by, state, answer_choice, answer_text.
     * Giving an answer sets state=answered + answered_at=now; state=open clears the answer fields.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $question = Question::query()->findOrFail($id);
        $data = $request->validate([
            'text' => ['sometimes', 'string', 'max:1000'],
            'context' => ['sometimes', 'nullable', 'string', 'max:500'],
            'type' => ['sometimes', Rule::in(Question::TYPES)],
            'asked_by' => ['sometimes', 'string', 'max:120'],
            'state' => ['sometimes', Rule::in(Question::STATES)],
            ...QuestionAnswer::rules(),
        ]);
        $question->fill(array_intersect_key($data, array_flip(['text', 'context', 'type', 'asked_by'])));

        $state = $data['state'] ?? null;
        $answering = array_key_exists('answer_choice', $data) || array_key_exists('answer_text', $data);
        if ($state === 'open') {
            QuestionAnswer::reopen($question);
        } elseif ($answering) {
            QuestionAnswer::answer($question, $data['answer_choice'] ?? null, $data['answer_text'] ?? null);
        }
        if ($state === 'closed') {
            $question->state = 'closed';
        } elseif ($state === 'answered' && ! $answering) {
            if ($question->answered_at === null) {
                throw ValidationException::withMessages(['state' => 'state=answered needs answer_choice and/or answer_text.']);
            }
            $question->state = 'answered';
        }
        // A type change must still fit the stored answer.
        if ($question->state === 'answered') {
            QuestionAnswer::check($question->type, $question->answer_choice, $question->answer_text);
        }
        $question->save();

        return response()->json(['data' => $question->fresh()->toView()]);
    }

    public function destroy(string $id): JsonResponse
    {
        Question::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
