<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Workspaces\CurrentWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds Captain Diego API user + Sanctum CEO token.
 * Plaintext token written once to .bridge-deploy-secrets (not committed).
 */
class BridgeApiSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'captain@lantis.internal'],
            [
                'name' => 'Captain William Diego',
                'password' => Hash::make(bin2hex(random_bytes(16))),
            ],
        );

        // Revoke prior CEO tokens so re-seed is deterministic for local use.
        $user->tokens()->where('name', 'ceo')->delete();

        $new = $user->createToken('ceo', ['ceo']);
        $token = $new->plainTextToken;

        // Multi-user: Captain's API user is a member of Tilen's workspace and the token acts there.
        $workspace = app(CurrentWorkspace::class)->default();
        if ($workspace !== null) {
            if (! $workspace->hasMember($user)) {
                $workspace->users()->attach($user->id, ['role' => 'member']);
            }
            $new->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
        }

        $path = base_path('.bridge-deploy-secrets');
        $existing = is_file($path) ? file_get_contents($path) : '';
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", (string) $existing) as $line) {
            if ($line === '' || str_starts_with($line, 'BRIDGE_API_TOKEN=')) {
                continue;
            }
            // keep gate password and comments
            $lines[] = $line;
        }
        if (! str_contains(implode("\n", $lines), 'BRIDGE_GATE_PASSWORD=')) {
            array_unshift($lines, 'BRIDGE_GATE_PASSWORD=lantis-internal');
        }
        $lines[] = 'BRIDGE_API_TOKEN='.$token;
        $lines[] = 'BRIDGE_API_USER=captain@lantis.internal';
        file_put_contents($path, implode("\n", array_filter($lines, fn ($l) => $l !== null))."\n");
        @chmod($path, 0600);
    }
}
