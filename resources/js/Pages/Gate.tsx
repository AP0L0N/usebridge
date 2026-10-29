import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { TopBar } from '../Components/TopBar';

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
    <>
      <TopBar nav={false} />
      <main className="gate">
        <form onSubmit={onSubmit}>
          <h1>Workspace gate</h1>
          <p>Shared LANTIS internal password.</p>
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
        </form>
      </main>
    </>
  );
}
