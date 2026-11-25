import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { COMPOSER_INPUT_DEFAULT } from '../../resources/js/lib/composer.ts';

const source = readFileSync(new URL('../../resources/js/Components/Kanban/TicketDetail.tsx', import.meta.url), 'utf8');

test('"Mark as my input for the PM" is ticked by default', () => {
  assert.equal(COMPOSER_INPUT_DEFAULT, true);
  assert.ok(source.includes('useState(COMPOSER_INPUT_DEFAULT)'));
});

test('a posted comment puts the checkbox back to the default, and sends what is ticked', () => {
  assert.ok(source.includes('setAsInput(COMPOSER_INPUT_DEFAULT)'));
  assert.ok(!source.includes('setAsInput(false)'));
  assert.ok(source.includes('is_owner_input: asInput'));
});
