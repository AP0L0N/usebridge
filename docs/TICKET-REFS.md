# Ticket references — storage and the `bridge:ticket-refs` backfill

Ticket keys such as `GP-118` in stored text open the ticket in a modal instead of navigating away
(portfolio-workstreams README §11). This page is for whoever runs the backfill on production (DevOps) and for anyone
changing the storage format. The API behaviour is in [API-TICKETS.md](API-TICKETS.md#ticket-references-in-text).

## Storage token

```
[[ticket:<ticket id>|<key when saved>]]      e.g.  [[ticket:tk-01jc4m…|GP-118]]
```

- **The id resolves.** Chips, the API and MCP always show the ticket's *current* key, looked up by id, so a reference
  survives a key or project-prefix change (README §11.1 asked for an id-based token).
- **The key after the bar is only a fallback label**: it is shown when the ticket can no longer be found (deleted), so
  the text still reads "GP-118" instead of losing the word. Nothing resolves by it. `[[ticket:<id>]]` without the bar is
  accepted on read too.
- Only keys of tickets that **exist in the row's workspace** are converted (any project of that workspace, so
  cross-project mentions work). Unknown keys stay plain text and render as plain text (no chip).
- Never converted: anything inside a URL, a Markdown link, inline code or a code block, an existing token, lowercase
  `gp-118`, ticket titles, workstream and project names.
- REST and MCP responses expand tokens back to plain current keys; only the web app sees tokens.
- Ticket **titles are stored plain**. The web board adds a read-time `title_refs` (the title with tokens) so card titles
  can show chips; it is never written.

New text is tokenized on save (every write path) from the moment this code is deployed. Text saved earlier stays plain
until the backfill below runs; plain keys simply render as plain text meanwhile, so nothing breaks in between.

## Columns the backfill rewrites

Production is MySQL (README.md). A token is longer than the key it replaces, so every column has its length here
(`TICKET_REF_FIELDS` on each model). A value whose tokenized form would not fit is **left exactly as it is** and counted
as *skipped*: a token is never cut by a short column. TEXT columns are checked against 65,535 bytes.

| table.column | type |
|---|---|
| `tickets.description` | TEXT |
| `tickets.ask` | varchar(280) |
| `tickets.ask_context` | varchar(500) |
| `tickets.blocked_reason` | varchar(140) |
| `ticket_comments.body` | TEXT |
| `projects.description` | TEXT |
| `projects.status_note` | varchar(280) |
| `project_status_events.note` | varchar(280) |
| `status_updates.body` | TEXT |
| `questions.text` | varchar(1000) |
| `questions.context` | varchar(500) |
| `questions.answer_text` | TEXT |
| `required_inputs.text` | TEXT |
| `next_steps.text` | varchar(500) |

The command prints this same list (one row per column, even when nothing changes). The same length rule applies on
save: if a user or agent writes a 140-character `blocked_reason` that mentions a key, it is stored as typed.

## The command

```
php artisan bridge:ticket-refs [--dry-run] [--revert] [--table=<table>]
```

| Option | Effect |
|---|---|
| *(none)* | plain keys → tokens, writes |
| `--dry-run` | computes everything, **writes nothing**, prints what would change |
| `--revert` | tokens → plain keys (the ticket's current key; the stored key if the ticket is gone) |
| `--table=` | only that table (`tickets`, `ticket_comments`, `projects`, `project_status_events`, `status_updates`, `questions`, `required_inputs`, `next_steps`); anything else exits 1 |

- **Not a migration.** Pushing to `main` deploys and runs migrations on production; this rewrite must not happen
  without a backup, so it is a command that someone runs by hand.
- Chunked by primary key (500 rows), one column at a time, raw queries: no model events, no notifications, `updated_at`
  untouched, no ticket events.
- **Idempotent**: tokens are never re-tokenized and plain text is never re-expanded; running it twice changes nothing
  the second time (dry-run then shows 0 rows to change).
- Only rows that could contain a reference are read (`LIKE '%-%'`, or `LIKE '%[[ticket:%'` for `--revert`).

Output (counts per `table.column`, then a total):

```
DRY RUN (nothing written) · plain keys → tokens
+------------------------------+--------------+-------------------+--------------------+--------------------+
| table.column                 | rows scanned | rows would change | refs would convert | skipped (too long) |
+------------------------------+--------------+-------------------+--------------------+--------------------+
| tickets.description          | 412          | 57                | 81                 | 0                  |
| …                            |              |                   |                    |                    |
| total                        | 1934         | 203               | 266                | 2                  |
+------------------------------+--------------+-------------------+--------------------+--------------------+
```

*rows scanned* = rows read for that column, *rows (would) change* = rows whose value changes, *refs (would) convert* =
tokens added (or, with `--revert`, removed), *skipped (too long)* = values left as they are because the tokenized text
would not fit the column.

## Runbook (production)

1. Deploy the code first (new writes are tokenized from then on; the backfill only handles older text).
2. Back up the database (at least the tables above).
3. `php artisan bridge:ticket-refs --dry-run` and keep the output. Check that the counts are plausible; a non-zero
   *skipped* is fine (those values stay plain text).
4. `php artisan bridge:ticket-refs` and keep the output. Its counts should match the dry run (new writes in between can
   make them differ slightly).
5. `php artisan bridge:ticket-refs --dry-run` again: *rows would change* must be 0 everywhere.

To undo: `php artisan bridge:ticket-refs --revert --dry-run`, then `--revert`. Revert also expands tokens written by
the app after the deploy (it cannot tell them apart), and it restores the **current** key of each ticket. A key is
always shorter than its token, so a revert never runs into a column length. To restore byte-for-byte, use the backup.
