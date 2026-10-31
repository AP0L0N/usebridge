<?php

namespace App\Http\Controllers;

use App\Models\CliUsageImport;
use App\Models\User;
use App\Services\Usage\CursorCsvImporter;
use App\Services\Usage\InvalidUsageDay;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * POST /usage/cursor-csv (multipart `file`): the Cursor dashboard CSV export into the current workspace
 * (CursorCsvImporter). Allowed for the owner of the current workspace (workspace_user role owner); 404 for everyone
 * else. 10 per hour per user (route throttle).
 */
class CursorCsvController extends Controller
{
    /** Whether this request may upload a Cursor CSV into the current workspace (EnsureUserWorkspace has set it). */
    public static function allowed(Request $request): bool
    {
        $workspace = app(CurrentWorkspace::class)->get();
        if ($workspace === null) {
            return false;
        }
        $user = Auth::guard('web')->user();

        return $user instanceof User
            && $workspace->users()->whereKey($user->id)->wherePivot('role', 'owner')->exists();
    }

    /** ISO time of the last Cursor import in the current workspace, null when none. */
    public static function lastImportAt(): ?string
    {
        $at = CliUsageImport::query()->where('tool', CursorCsvImporter::TOOL)->max('imported_at');

        return $at === null ? null : Carbon::parse($at)->toIso8601String();
    }

    public function store(Request $request, CursorCsvImporter $importer): JsonResponse
    {
        abort_unless(self::allowed($request), 404);

        $request->validate(['file' => ['required', 'file', 'max:'.(CursorCsvImporter::MAX_BYTES / 1024)]]);

        try {
            $result = $importer->import((string) $request->file('file')->get());
        } catch (InvalidUsageDay $e) {
            return response()->json(['message' => 'The Cursor CSV is invalid; nothing was imported.', 'errors' => $e->errors], 422);
        }

        return response()->json(['data' => $result]);
    }
}
