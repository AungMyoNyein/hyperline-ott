"use client";
import { useEffect, useState } from "react";
import { api, errorText } from "../../lib/api";
const labels: Record<string, string> = {
  continue: "Continue Watching",
  trending: "Trending Movies",
  latest: "Latest Movies",
  genres: "Movies by Genre",
  series: "Series",
  vertical: "Vertical dramas",
  featured: "Featured Movies",
};
export default function HomepageEditor({ token }: { token: string }) {
  const [sections, setSections] = useState<string[]>([]);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const c = new AbortController();
    api<{ data: { sections: string[] } }>("/admin/homepage", token, {
      signal: c.signal,
    })
      .then((r) => setSections(r.data.sections))
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      });
    return () => c.abort();
  }, [token, revision]);
  function move(index: number, delta: number) {
    const next = [...sections];
    [next[index], next[index + delta]] = [next[index + delta], next[index]];
    setSections(next);
    setMessage("");
  }
  return (
    <section className="workspace-card" aria-label="Homepage settings">
      <h2>Homepage sections</h2>
      <p>
        Featured titles are selected in Movies. Empty sections and repeated
        movie rows are hidden automatically.
      </p>
      {error && (
        <p role="alert">
          {error}
          <button
            onClick={() => {
              setError("");
              setRevision((r) => r + 1);
            }}
          >
            Retry
          </button>
        </p>
      )}
      {message && <p role="status">{message}</p>}
      <ol>
        {sections.map((s, i) => (
          <li key={s}>
            {labels[s]}{" "}
            <button
              aria-label={`Move ${labels[s]} up`}
              disabled={busy || i === 0}
              onClick={() => move(i, -1)}
            >
              Up
            </button>{" "}
            <button
              aria-label={`Move ${labels[s]} down`}
              disabled={busy || i === sections.length - 1}
              onClick={() => move(i, 1)}
            >
              Down
            </button>
          </li>
        ))}
      </ol>
      <button
        disabled={busy || !sections.length}
        onClick={async () => {
          setBusy(true);
          setError("");
          try {
            await api("/admin/homepage", token, {
              method: "PATCH",
              body: JSON.stringify({ sections }),
            });
            setMessage("Homepage order saved.");
          } catch (e) {
            setError(errorText(e));
          } finally {
            setBusy(false);
          }
        }}
      >
        Save homepage order
      </button>
    </section>
  );
}
