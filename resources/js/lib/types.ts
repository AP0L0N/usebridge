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
  openQuestions: number;
  searchText?: string;
}

export interface InputView {
  id: string;
  text: string;
  waitingOn: string;
  since: string;
  resolvedAt: string | null;
  resolvedBy: string | null;
  resolvedByLabel: string | null;
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

export type NextStepKind = 'in_progress' | 'blocker';

export interface NextStepView {
  id: string;
  kind: NextStepKind;
  text: string;
  owner: string | null;
  agent_id: string | null;
  position: number;
  done: boolean;
  created_at: string | null;
  updated_at: string | null;
}

export interface TimelineWindow {
  days: number;
  step: number;
  hasMore: boolean;
}

export type QuestionType = 'yesno' | 'text';
export type QuestionState = 'open' | 'answered' | 'closed';
export type AnswerChoice = 'yes' | 'no' | 'skip' | 'other';

export interface QuestionView {
  id: string;
  project_id: string;
  text: string;
  context: string | null;
  type: QuestionType;
  asked_by: string;
  state: QuestionState;
  answer_choice: AnswerChoice | null;
  answer_text: string | null;
  answered_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}
