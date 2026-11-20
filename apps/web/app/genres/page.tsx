"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api, errorText, Genre } from "../../lib/api";
import { EmptyState, ErrorState, Skeleton } from "../components/ui";
export default function Genres() {
  const [genres, setGenres] = useState<Genre[] | null>(null);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const c = new AbortController();
    setError("");
    api<{ data: Genre[] }>("/genres", "", { signal: c.signal })
      .then((j) => setGenres(j.data))
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      });
    return () => c.abort();
  }, [revision]);
  return (
    <main>
      <div className="page-head">
        <p className="eyebrow">FOLLOW YOUR MOOD</p>
        <h1>A world of genres.</h1>
        <p className="muted">Find a story that feels like you.</p>
      </div>
      {error ? (
        <ErrorState message={error} retry={() => setRevision((r) => r + 1)} />
      ) : !genres ? (
        <Skeleton />
      ) : genres.length ? (
        <div className="genre-grid">
          {genres.map((g, i) => (
            <Link
              className="genre-tile"
              style={{ "--genre-index": i % 4 } as React.CSSProperties}
              key={g.id}
              href={`/movies?genre=${encodeURIComponent(g.slug)}`}
            >
              <span className="eyebrow">EXPLORE</span>
              <h2>{g.name}</h2>
              <span aria-hidden="true">↗</span>
            </Link>
          ))}
        </div>
      ) : (
        <EmptyState title="New worlds are on their way.">
          Genres will appear here when available.
        </EmptyState>
      )}
    </main>
  );
}
