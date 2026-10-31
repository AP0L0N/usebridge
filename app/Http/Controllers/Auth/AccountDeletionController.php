<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountDeletion;
use App\Services\Auth\LoginService;
use App\Services\Auth\WorkspaceExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Danger zone (docs/MULTI-USER-PLAN.md §8 account delete): request deletion (typed email + current password), export the workspace. */
class AccountDeletionController extends Controller
{
    public function request(Request $request, AccountDeletion $deletion, LoginService $login): JsonResponse
    {
        // Typed email + the current password (Laravel's current_password rule, web guard): a hijacked session alone
        // cannot delete the account.
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);
        $user = $request->user();
        $deletion->request($user, $data['email']);
        $login->logout($request->session());

        return response()->json([
            'message' => 'Your account will be deleted in '.AccountDeletion::GRACE_DAYS.' days. Log in before then to cancel.',
            'purge_at' => AccountDeletion::purgeAt($user)->toIso8601String(),
        ]);
    }

    public function export(WorkspaceExport $export): JsonResponse
    {
        $data = $export->export();

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="bridge-'.$data['workspace']['slug'].'-'.now()->format('Y-m-d').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
