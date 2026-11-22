"use client";
import { FormEvent, Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { api, errorText, Genre, Movie, Page } from "../../lib/api";
import { movieCatalogHref, movieCatalogParams } from "../../lib/movie-catalog";
import { Cards } from "../cards";

export default function Catalog() {
  return (
    <Suspense
      fallback={
        <main>
          <p role="status">Loading movies…</p>
        </main>
      }
    >
      <CatalogContent />
    </Suspense>
  );
}
function CatalogContent() {
  const params = useSearchParams();
  const genre = params.get("genre") || "";
  const featured = params.get("featured") === "1";
  const q = params.get("q") || "";
  const rawPage = Number(params.get("page"));
  const page = Number.isSafeInteger(rawPage) && rawPage > 0 ? rawPage : 1;
  const [genres, setGenres] = useState<Genre[]>([]);
  const [genreError, setGenreError] = useState("");
  const [query, setQuery] = useState(q);
  const [validation, setValidation] = useState("");
  const [result, setResult] = useState<Page<Movie> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  const last = result?.meta.last_page || 1;
  const active = !!(genre || featured || q);
  const catalogHref = movieCatalogHref(new URLSearchParams(params.toString()));
  useEffect(() => {
    setQuery(q);
    setValidation("");
  }, [q]);
  useEffect(() => {
    const c = new AbortController();
    setGenreError("");
    api<{ data: Genre[] }>("/genres", "", { signal: c.signal })
      .then((j) => {
        if (!c.signal.aborted) setGenres(j.data);
      })
      .catch((e) => {
        if (!c.signal.aborted) setGenreError(errorText(e));
      });
    return () => c.abort();
  }, [revision]);
  useEffect(() => {
    const c = new AbortController();
    setLoading(true);
    setError("");
    const filters = new URLSearchParams({ page: String(page) });
    if (genre) filters.set("genre", genre);
    if (featured) filters.set("featured", "1");
    if (q) filters.set("q", q);
    api<Page<Movie>>(`/movies?${filters}`, "", { signal: c.signal })
      .then((j) => {
        if (!c.signal.aborted) setResult(j);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [genre, featured, q, page, revision]);
  function update(values: Record<string, string>) {
    const next = movieCatalogParams(new URLSearchParams(params.toString()));
    for (const [key, value] of Object.entries(values)) {
      if (value) next.set(key, value);
      else next.delete(key);
    }
    window.history.replaceState(null, "", movieCatalogHref(next));
    setValidation("");
  }
  function search(e: FormEvent) {
    e.preventDefault();
    const value = query.trim();
    if (Array.from(value).length === 1) {
      setValidation("Enter at least two characters to search.");
      return;
    }
    setQuery(value);
    update({ q: value, page: "" });
    // Resubmitting the same query must also recover from a failed request.
    setRevision((r) => r + 1);
  }
  function clearAll() {
    setQuery("");
    update({ q: "", genre: "", featured: "", page: "" });
    setRevision((r) => r + 1);
  }
  return (
    <main className="movies-catalog">
      <section className="catalog-heading" aria-labelledby="catalog-title">
        <p className="eyebrow">THE COLLECTION</p>
        <h1 id="catalog-title">Find your next story</h1>
        <p className="muted">
          Explore movies by title, genre or a featured pick.
        </p>
      </section>
      <form
        className="catalog-toolbar"
        onSubmit={search}
        role="search"
        aria-label="Movie catalog search"
      >
        <div className="catalog-search">
          <label htmlFor="movie-search">Search movies</label>
          <div className="catalog-search-row">
            <input
              id="movie-search"
              type="search"
              maxLength={120}
              placeholder="Title or synopsis"
              value={query}
              aria-invalid={!!validation}
              aria-describedby={validation ? "search-validation" : undefined}
              onChange={(e) => {
                setQuery(e.target.value);
                setValidation("");
              }}
            />
            <button type="submit">Search</button>
            {(query || q) && (
              <button
                type="button"
                className="secondary"
                aria-label="Clear search"
                onClick={() => {
                  setQuery("");
                  update({ q: "", page: "" });
                  setRevision((r) => r + 1);
                }}
              >
                Clear
              </button>
            )}
          </div>
        </div>
        <label className="catalog-genre">
          Genre
          <select
            aria-label="Genre"
            value={genre}
            onChange={(e) => update({ genre: e.target.value, page: "" })}
          >
            <option value="">All genres</option>
            {genre && !genres.some((g) => g.slug === genre) && (
              <option value={genre}>{genre}</option>
            )}
            {genres.map((g) => (
              <option key={g.id} value={g.slug}>
                {g.name}
              </option>
            ))}
          </select>
        </label>
        <label className="check">
          <input
            type="checkbox"
            checked={featured}
            onChange={(e) =>
              update({ featured: e.target.checked ? "1" : "", page: "" })
            }
          />
          Featured only
        </label>
        {active && (
          <button type="button" className="secondary" onClick={clearAll}>
            Clear filters
          </button>
        )}
      </form>
      {validation && (
        <p id="search-validation" className="error" role="alert">
          {validation}
        </p>
      )}
      {genreError && (
        <p className="error" role="alert">
          Genres could not be loaded.{" "}
          <button onClick={() => setRevision((r) => r + 1)}>
            Retry genres
          </button>
        </p>
      )}
      <section
        className="catalog-results"
        aria-label="Movie results"
        aria-busy={loading}
      >
        <p className="catalog-count muted" role="status" aria-live="polite">
          {loading
            ? "Loading movies…"
            : error
              ? "Movies could not be loaded."
              : `${result?.meta.total ?? 0} ${(result?.meta.total ?? 0) === 1 ? "movie" : "movies"}${active ? " match your filters" : " to explore"}`}
        </p>
        {error ? (
          <div className="catalog-message error" role="alert">
            <h2>We couldn’t load the movies.</h2>
            <p>{error}</p>
            <button onClick={() => setRevision((r) => r + 1)}>Retry</button>
          </div>
        ) : loading ? (
          <div className="catalog-loading" aria-hidden="true">
            Finding your next story…
          </div>
        ) : result?.data.length ? (
          <Cards movies={result.data} catalogHref={catalogHref} />
        ) : (
          <div className="catalog-message">
            <h2>No movies found</h2>
            <p>Try a different title or genre, or clear your filters.</p>
            {active && (
              <button className="secondary" onClick={clearAll}>
                Clear filters
              </button>
            )}
          </div>
        )}
        {!loading && !error && !!result?.data.length && (
          <nav className="pagination" aria-label="Catalog pages">
            <button
              disabled={page === 1}
              onClick={() => update({ page: page > 2 ? String(page - 1) : "" })}
            >
              Previous
            </button>
            <span>
              Page {page} of {last}
            </span>
            <button
              disabled={page >= last}
              onClick={() => update({ page: String(page + 1) })}
            >
              Next
            </button>
          </nav>
        )}
      </section>
    </main>
  );
}
