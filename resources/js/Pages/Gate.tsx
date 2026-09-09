import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';

export default function Gate() {
  const { data, setData, post, processing, errors, reset } = useForm({
    password: '',
  });
  const [localError, setLocalError] = useState(false);

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    post('/gate', {
      onError: () => {
        setLocalError(true);
        reset('password');
      },
      onSuccess: () => setLocalError(false),
    });
  }

  return (
    <div className="shell">
      <div className="chrome">
        <div className="brand-bar">
          <div className="brand">
            <span className="brand-mark" aria-hidden="true" /> BRIDGE
          </div>
          <span className="env-chip">internal</span>
        </div>
      </div>
      <main className="gate-main">
        <form className="gate-card" onSubmit={onSubmit}>
          <h1>Workspace gate</h1>
          <p>
            Shared LANTIS internal password. No per-agent ACL in v1. No public
            signup.
          </p>
          <label htmlFor="gate-password">Password</label>
          <input
            id="gate-password"
            type="password"
            name="password"
            autoComplete="current-password"
            value={data.password}
            onChange={(e) => {
              setData('password', e.target.value);
              if (localError) setLocalError(false);
            }}
            autoFocus
          />
          {(localError || errors.password) && (
            <p className="gate-error" role="alert">
              Wrong password.
            </p>
          )}
          <button className="btn btn-cyan" type="submit" disabled={processing}>
            Enter Bridge
          </button>
        </form>
      </main>
    </div>
  );
}
