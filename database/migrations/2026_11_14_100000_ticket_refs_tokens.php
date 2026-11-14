<?php

use App\Support\TicketRefs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Ticket references, backwards migration: plain "KEY-<num>" mentions of tickets that exist (in the row's own workspace)
 * become `[[ticket:<id>|<key>]]` tokens in every stored text column of TicketRefs::MODELS (each model's
 * TICKET_REF_FIELDS: ticket descriptions / asks / blocked reasons, comments, project descriptions and status notes,
 * status events, status updates, legacy questions / required inputs / next steps). Keys of tickets that do not exist,
 * and anything inside URLs, Markdown links, code or an existing token, stay as they are. New writes are tokenized on
 * save (StoresTicketRefs).
 *
 * Chunked by primary key with raw queries (no model events, updated_at untouched). Idempotent: running it again changes
 * nothing. down() expands every token back to the ticket's current plain key (or the key stored in the token when the
 * ticket is gone). The number of references converted per table is logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Log::info('Ticket references tokenized', TicketRefs::backfill());
    }

    public function down(): void
    {
        Log::info('Ticket references expanded to plain keys', TicketRefs::backfill(expand: true));
    }
};
