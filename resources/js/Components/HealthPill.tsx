type Health = 'green' | 'yellow' | 'red';

const LABEL: Record<Health, string> = {
  green: 'Green',
  yellow: 'Yellow',
  red: 'Red',
};

export function HealthPill({ health }: { health: Health }) {
  return <span className={`pill pill-${health}`}>{LABEL[health]}</span>;
}
