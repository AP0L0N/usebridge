/**
 * Quick-pick chips (r4 OptionChip): native radios in a role=radiogroup, so arrow keys, Space and the form semantics
 * come from the browser. States: default / hover (paper) / selected (ink-950 fill, signal dot) / focus (2px agent
 * ring) / disabled (50%).
 */
export function OptionChips({ name, options, value, onChange, disabled, labelledBy }: {
  name: string;
  options: string[];
  value: string | null;
  onChange: (v: string) => void;
  disabled: boolean;
  labelledBy: string;
}) {
  return (
    <div className="qp-chips" role="radiogroup" aria-labelledby={labelledBy}>
      {options.map((o) => (
        <label key={o} className="qp-chip">
          <input type="radio" name={name} value={o} checked={value === o} disabled={disabled} onChange={() => onChange(o)} />
          <span>{o}</span>
        </label>
      ))}
    </div>
  );
}
