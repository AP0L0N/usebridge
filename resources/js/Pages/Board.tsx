import { ToastHost } from '../Components/Kanban/Bits';
import { BoardView, type BoardProps } from '../Components/Kanban/BoardView';
import { TicketRefHost } from '../Components/TicketRefs';
import { ticketUrl } from '../lib/kanban';
import { boardUrl } from '../lib/workstreams';

/** The dedicated board page (/projects/{slug}?ws=…, deep link /projects/{slug}/tickets/{key}). */
export default function BoardPage(props: BoardProps) {
  const slug = props.board.project.slug;
  return (
    <div className="kanban app">
      <ToastHost>
        <TicketRefHost reloadOnly={['board', 'statusHistory', 'latestUpdate']}>
          <BoardView {...props} here={boardUrl(slug, props.workstream)} ticketHref={(key) => ticketUrl(slug, key)} />
        </TicketRefHost>
      </ToastHost>
    </div>
  );
}
