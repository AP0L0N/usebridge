import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AppTopBar } from '../Components/Shell/AppTopBar';

/** AUTH-1 gate. configured false: no password is set on the server, so every attempt is rejected (fails closed). */
export default function Gate({ configured = true }: { configured?: boolean }) {
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
    <>
      <AppTopBar variant="guest" />
      <main className="gate">
        <form onSubmit={onSubmit}>
          <h1>Workspace gate</h1>
          <p>Shared workspace password.</p>
          {!configured && <p className="err" role="status">The workspace gate is not configured. Log in with your email.</p>}
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
            <p className="err" role="alert">Wrong password.</p>
          )}
          <button className="btn" type="submit" disabled={processing}>Enter Bridge</button>
          <p className="alt">Have your own account? <a href="/login">Log in with your email</a></p>
        </form>
      </main>
    </>
  );
}
