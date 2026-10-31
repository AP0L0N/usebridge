<?php

namespace App\Services;

use App\Models\RequiredInput;
use Illuminate\Support\Carbon;

/** Tick / untick a Required input. Status is derived on read, so it recalculates immediately. */
final class InputResolution
{
    public static function resolve(RequiredInput $input, string $by): RequiredInput
    {
        if ($input->resolved_at === null) {
            $input->forceFill(['resolved_at' => Carbon::now(), 'resolved_by' => $by])->save();
        }

        return $input;
    }

    public static function reopen(RequiredInput $input): RequiredInput
    {
        $input->forceFill(['resolved_at' => null, 'resolved_by' => null])->save();

        return $input;
    }
}
