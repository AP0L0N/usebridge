/** Client-side reading of the Power Saver number field. The wire value is a JSON integer or null. */

export type PercentParse = { ok: true; value: number | null } | { ok: false; message: string };

/** Empty field is "no threshold" (sent as null). Anything else must be a whole number in range. */
export function parsePercent(raw: string, min: number, max: number, invalidMessage: string): PercentParse {
  const text = raw.trim();
  if (text === '') return { ok: true, value: null };
  if (!/^\d+$/.test(text)) return { ok: false, message: invalidMessage };
  const n = Number(text);
  if (n < min || n > max) return { ok: false, message: invalidMessage };
  return { ok: true, value: n };
}

/** A null threshold has no slider. 0 is a real threshold, so the slider is shown. */
export function showStopSlider(parsed: PercentParse): boolean {
  return parsed.ok && parsed.value !== null;
}

/** 422 with field errors is a value problem. Anything else, including a dropped connection, is not. */
export function saveFailureKind(status: number, hasFieldErrors: boolean): 'validation' | 'network' {
  return status === 422 && hasFieldErrors ? 'validation' : 'network';
}
