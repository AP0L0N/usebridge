<?php

namespace Tests\Concerns;

/** Points bridge.roster.path (BRIDGE_ROSTER_PATH) at a temp JSON file with a synthetic roster for this test. */
trait RosterFile
{
    /** @param  array<string, mixed>|string  $roster  roster array, or raw file contents */
    protected function useRoster(array|string $roster): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bridge-roster-');
        file_put_contents($path, is_string($roster) ? $roster : json_encode($roster, JSON_THROW_ON_ERROR));
        config(['bridge.roster.path' => $path]);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
