<?php

namespace Database\Seeders;

use App\Models\User;
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

        $token = $user->createToken('ceo', ['ceo'])->plainTextToken;

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
