"use client";
import { useEffect, useState } from "react";
import { api, errorText, Movie, Page } from "../../lib/api";
import ArtworkPreview from "./artwork-preview";
export default function ContentTools({
  token,
  mode,
  edit,
}: {
  token: string;
  mode: string;
  edit: (movie: Movie) => void;
}) {
  const [rows, setRows] = useState<Movie[]>([]);
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const c = new AbortController();
    setLoading(true);
    setError("");
    api<Page<Movie>>(`/admin/movies?per_page=20&page=${page}`, token, {
      signal: c.signal,
    })
      .then((r) => {
        setRows(r.data);
        setLast(r.meta.last_page);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [token, page, revision]);
  const title =
    mode === "artwork"
      ? "Artwork"
      : mode === "sources"
        ? "Video Sources"
        : "Content QA";
  return (
    <section className="workspace-card" aria-label={title}>
      <h2>{title}</h2>
      <p className="muted">
        Review existing movie assets and readiness. Open an editor to make an
        intentional change; manage episode sources in Series.
      </p>
      {error && (
        <p role="alert">
          {error}
          <button onClick={() => setRevision((r) => r + 1)}>Retry</button>
        </p>
      )}
      {loading ? (
        <p role="status">Loading content…</p>
      ) : (
        rows.map((m) => (
          <article className="workspace-card" key={m.id}>
            <h3 lang={/[\u1000-\u109f]/.test(m.title) ? "my" : "en"}>
              {m.title}
            </h3>
            {mode === "artwork" ? (
              <ArtworkPreview
                poster={m.poster_url || ""}
                backdrop={m.backdrop_url || ""}
              />
            ) : mode === "sources" ? (
              <ul>
                {(m.sources || []).map((s, i) => (
                  <li key={s.id || i}>
                    {s.label} · {s.kind.toUpperCase()} ·{" "}
                    {s.enabled ? "Enabled" : "Disabled"}
                    {s.is_default ? " · Default" : ""}
                  </li>
                ))}
                {!m.sources?.length && <li>No sources configured.</li>}
              </ul>
            ) : (
              <ul>
                <li>
                  {m.published
                    ? "Published"
                    : "Draft — review before publishing"}
                </li>
                <li>
                  {m.playable
                    ? "Playable source available"
                    : "No playable source"}
                </li>
                <li>
                  {m.genres.length
                    ? `${m.genres.length} genres assigned`
                    : "No genre assigned"}
                </li>
                <li>
                  {!m.poster_url ||
                  /^https:\/\/i\.ytimg\.com\//.test(m.poster_url)
                    ? "Missing portrait artwork — manual review required"
                    : "Poster URL configured — verify 2:3 in Artwork previews"}
                </li>
                <li>
                  {m.backdrop_url
                    ? "Backdrop URL configured"
                    : "Backdrop missing — fallback shown"}
                </li>
              </ul>
            )}
            <button onClick={() => edit(m)}>Edit {m.title}</button>
          </article>
        ))
      )}
      <nav aria-label="Content review pages">
        <button
          disabled={loading || page <= 1}
          onClick={() => setPage((p) => p - 1)}
        >
          Previous
        </button>
        <span>
          Page {page} of {last}
        </span>
        <button
          disabled={loading || page >= last}
          onClick={() => setPage((p) => p + 1)}
        >
          Next
        </button>
      </nav>
    </section>
  );
}
