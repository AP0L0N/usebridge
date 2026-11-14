<?php

namespace App\Models\Concerns;

use App\Support\TicketRefs;
use Illuminate\Database\Eloquent\Model;

/**
 * Text columns that may mention tickets: on save, mentions of existing ticket keys ("BR-9") in the model's
 * TICKET_REF_FIELDS become `[[ticket:<id>|<key>]]` tokens (TicketRefs). Covers every write path that saves the model
 * (web controllers, REST API, MCP tools, services).
 *
 * @mixin Model
 */
trait StoresTicketRefs
{
    public static function bootStoresTicketRefs(): void
    {
        // creating/updating (not saving): BelongsToWorkspace's creating hook, booted first, has set workspace_id by then.
        $tokenize = function (Model $model) {
            foreach ($model::TICKET_REF_FIELDS as $field) {
                $value = $model->getAttribute($field);
                if (is_string($value) && $model->isDirty($field)) {
                    $workspace = $model->getAttribute('workspace_id');
                    $model->setAttribute($field, TicketRefs::tokenize($value, $workspace !== null ? (int) $workspace : null));
                }
            }
        };
        static::creating($tokenize);
        static::updating($tokenize);
    }
}
