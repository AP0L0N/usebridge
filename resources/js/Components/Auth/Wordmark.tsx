/** The Bridge lockup for dark surfaces (public/brand/lockup-dark.svg, from the brand files; never redrawn). */
export function Wordmark({ size = 40, label }: { size?: 28 | 40; label: string }) {
  return (
    <a className="auth-wordmark" href="/">
      <img src="/brand/lockup-dark.svg" alt={label} width={Math.round((149 * size) / 40)} height={size} />
    </a>
  );
}
