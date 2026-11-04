import type { WriteSource } from './kanban';

/** r4 Questions panel (Board prop `questions`, docs/API-TICKETS.md "r4 Questions panel: UI hook points"). */
export type QuestionState = 'open' | 'answered' | 'closed' | 'dismissed';

export interface Question {
  id: string;
  ticket_id: string;
  ticket_key: string;
  project_id: string;
  text: string;
  context: string | null;
  /** [] = free text; ["Yes","No"] = yes/no; otherwise multiple choice (plus the optional note). */
  options: string[];
  allow_text: boolean;
  asked_by: { id: string | null; name: string | null; role: string | null; type: 'agent' | 'human' | null; source: WriteSource | null };
  asked_at: string | null;
  state: QuestionState;
  answer_choice: string | null;
  answer_text: string | null;
  answered_at: string | null;
  picked_up_at: string | null;
  closed_at: string | null;
  dismissed_at: string | null;
}

export interface QuestionsPanelData {
  open: Question[];
  answered: Question[];
  /** closed + dismissed, newest first, at most 20. */
  closed: Question[];
  has_any: boolean;
}

export const ANSWER_MAX = 2000;
/** The counter shows from here on. */
export const COUNTER_FROM = 1800;

export const questionUrl = (slug: string, id: string, action: 'answer' | 'dismiss' | 'restore') =>
  `/projects/${encodeURIComponent(slug)}/questions/${encodeURIComponent(id)}/${action}`;

/** The answer read back: "At local sunrise — Fallback to 7:00 if location is off." */
export function answerReadBack(q: Pick<Question, 'answer_choice' | 'answer_text'>): string {
  const c = q.answer_choice?.trim() || null;
  const x = q.answer_text?.trim() || null;
  return c && x ? `${c} — ${x}` : (c ?? x ?? '');
}

/** "Ava" → "AV", "Builder" → "BU" (the r4 avatar shows two letters). */
export function initials2(name: string | null | undefined): string {
  const w = (name ?? '').trim();
  return (w ? w.slice(0, 2) : '?').toUpperCase();
}

/** ⌘↵ on Apple platforms, Ctrl+Enter elsewhere. */
export function isApple(): boolean {
  if (typeof navigator === 'undefined') return false;
  const nav = navigator as Navigator & { userAgentData?: { platform?: string } };
  return /mac|iphone|ipad|ipod/i.test(nav.userAgentData?.platform || nav.platform || nav.userAgent);
}

/** Whole days a question has been waiting (by elapsed time, like the board's waiting age). */
export function waitingDays(askedAt: string | null, nowIso: string): number {
  if (!askedAt) return 0;
  return Math.max(0, Math.floor((new Date(nowIso).getTime() - new Date(askedAt).getTime()) / 86_400_000));
}
