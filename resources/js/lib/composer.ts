// The ticket comment box (the Composer in Components/Kanban/TicketDetail.tsx). UI only: the comment API's own default
// for `is_owner_input` is unchanged, so comments posted by agents are not marked.

/** "Mark as my input for the PM" starts ticked, and is ticked again after a comment is posted. */
export const COMPOSER_INPUT_DEFAULT = true;
