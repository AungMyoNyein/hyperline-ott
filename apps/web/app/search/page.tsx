"use client";
import { FormEvent, useEffect, useState } from "react";
import { api, errorText, Movie, Page, Series } from "../../lib/api";
import { Cards, SeriesCards } from "../cards";
import { EmptyState, ErrorState, Skeleton } from "../components/ui";
export default function Search() {
  const [value, setValue] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<{
    movies: Page<Movie>;
    series: Page<Series>;
  } | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    if (!query) return;
    const c = new AbortController();
    setLoading(true);
    setError("");
    setData(null);
    const params = new URLSearchParams({ q: query, page: String(page) });
    Promise.all([
      api<Page<Movie>>(`/movies?${params}`, "", { signal: c.signal }),
      api<Page<Series>>(`/series?${params}`, "", { signal: c.signal }),
    ])
      .then(([movies, series]) => setData({ movies, series }))
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [query, page, revision]);
  function submit(e: FormEvent) {
    e.preventDefault();
    const q = value.trim();
    if (q.length < 2) {
      setError("Enter at least two characters to search.");
      return;
    }
    setQuery(q);
    setPage(1);
  }
  const last = data
    ? Math.max(data.movies.meta.last_page, data.series.meta.last_page)
    : 1;
  return (
    <main>
      <div className="page-head">
        <p className="eyebrow">SOMETHING IN MIND?</p>
        <h1>Find your next obsession.</h1>
        <p className="muted">Search movies and series by title or synopsis.</p>
      </div>
      <form className="search-form" onSubmit={submit}>
        <label className="grow">
          Search titles
          <input
            autoComplete="off"
            type="search"
            placeholder="A title, a story, a new discovery…"
            minLength={2}
            maxLength={120}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            required
          />
        </label>
        <button>Search</button>
      </form>
      {error ? (
        <ErrorState message={error} retry={() => setRevision((r) => r + 1)} />
      ) : loading ? (
        <Skeleton />
      ) : data ? (
        <>
          <section className="rail">
            <h2>Movies</h2>
            {data.movies.data.length ? (
              <Cards movies={data.movies.data} />
            ) : (
              <p className="muted">No matching movies on this page.</p>
            )}
          </section>
          <section className="rail">
            <h2>Series</h2>
            {data.series.data.length ? (
              <SeriesCards series={data.series.data} />
            ) : (
              <p className="muted">No matching series on this page.</p>
            )}
          </section>
          <nav className="pagination" aria-label="Search pages">
            <button disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Previous
            </button>
            <span>
              Page {page} of {last}
            </span>
            <button
              disabled={page >= last}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </button>
          </nav>
        </>
      ) : (
        <EmptyState title="Every great story starts somewhere.">
          Search the collection to find yours.
        </EmptyState>
      )}
    </main>
  );
}
