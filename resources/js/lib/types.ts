export type Status = 'smooth' | 'waiting' | 'archived';

export interface ProjectSummary {
  id: string;
  name: string;
  slug: string;
  status: Status;
  level: 1 | 2 | 3 | null;
  ageDays: number | null;
  archived: boolean;
  reopened: boolean;
  latestLine: string;
  lastUpdatedAt: string | null;
  openInputs: number;
  searchText?: string;
}

export interface InputView {
  id: string;
  text: string;
  waitingOn: string;
  since: string;
  doneAt: string | null;
  doneBy: string | null;
  open: boolean;
  ageDays: number | null;
  level: 1 | 2 | 3 | null;
}

export interface UpdateView {
  id: string;
  body: string;
  agentName: string;
  createdAt: string | null;
}
