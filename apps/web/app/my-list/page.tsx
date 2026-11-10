"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api, errorText, Movie, Page } from "../../lib/api";
import { useSession } from "../session";
export default function MyList() {
  const { token } = useSession();
  const [rows, setRows] = useState<
    {
      id: number;
      movie: Movie;
    }[]
  >([]);
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [revision, setRevision] = useState(0);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => {
    setRows([]);
    if (!token) return;
    const c = new AbortController();
    setLoading(true);
    setError("");
    api<
      Page<{
        id: number;
        movie: Movie;
      }>
    >(`/watchlist?page=${page}`, token, { signal: c.signal })
      .then((j) => {
        setRows(j.data);
        setLast(j.meta.last_page);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [token, page, revision]);
  async function remove(movie: Movie) {
    setBusy(true);
    setError("");
    try {
      await api(`/watchlist/${movie.id}`, token, { method: "DELETE" });
      if (rows.length === 1 && page > 1) setPage((p) => p - 1);
      else setRevision((r) => r + 1);
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <main>
      <h1>My List</h1>
      {!token ? (
        <p>
          <Link href="/login?next=%2Fmy-list">Sign in to view My List</Link>
        </p>
      ) : (
        <>
          {error && (
            <p className="error" role="alert">
              {error}
              <button onClick={() => setRevision((r) => r + 1)}>Retry</button>
            </p>
          )}
          {loading ? (
            <p role="status">Loading My List…</p>
          ) : !rows.length && !error ? (
            <p>
              Your list is empty. <Link href="/movies">Explore movies</Link>
            </p>
          ) : (
            <div className="grid">
              {rows.map((row) => (
                <article className="saved-movie" key={row.id}>
                  <Link href={`/movies/${row.movie.slug}`}>
                    <h2>{row.movie.title}</h2>
                  </Link>
                  <button
                    disabled={busy}
                    onClick={() => remove(row.movie)}
                    aria-label={`Remove ${row.movie.title} from My List`}
                  >
                    Remove
                  </button>
                </article>
              ))}
            </div>
          )}
          <nav className="pagination" aria-label="My List pages">
            <button
              disabled={page <= 1 || loading || busy}
              onClick={() => setPage((p) => p - 1)}
            >
              Previous
            </button>
            <span>
              Page {page} of {last}
            </span>
            <button
              disabled={page >= last || loading || busy}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </button>
          </nav>
        </>
      )}
    </main>
  );
}
