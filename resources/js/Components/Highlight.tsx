export function Highlight({ text, q }: { text: string; q: string }) {
  const needle = q.trim();
  if (!needle) return <>{text}</>;
  const lower = text.toLowerCase(), n = needle.toLowerCase();
  const out: React.ReactNode[] = [];
  let i = 0, k = 0;
  for (;;) {
    const j = lower.indexOf(n, i);
    if (j < 0) break;
    if (j > i) out.push(text.slice(i, j));
    out.push(<mark key={k++}>{text.slice(j, j + n.length)}</mark>);
    i = j + n.length;
  }
  out.push(text.slice(i));
  return <>{out}</>;
}
