"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api, errorText, Movie } from "../../../lib/api";
import { useSession } from "../../session";
export default function Detail({ slug }: { slug: string }) {
  const { token } = useSession();
  const [movie, setMovie] = useState<Movie | null>(null);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    const c = new AbortController();
    setMovie(null);
    setError("");
    api<{ data: Movie }>(`/movies/${encodeURIComponent(slug)}`, "", {
      signal: c.signal,
    })
      .then((j) => setMovie(j.data))
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      });
    return () => c.abort();
  }, [slug]);
  async function watchlist() {
    if (!movie) return;
    setBusy(true);
    setMessage("");
    try {
      await api("/watchlist", token, {
        method: "POST",
        body: JSON.stringify({ movie_id: movie.id }),
      });
      setMessage("Added to your watchlist.");
    } catch (e) {
      setMessage(errorText(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <main>
      {error ? (
        <p role="alert" className="error">
          {error}
        </p>
      ) : !movie ? (
        <p role="status">Loading movie…</p>
      ) : (
        <section className="detail-hero-inner">
          {movie.poster_url && (
            <img
              className="detail-poster"
              src={movie.poster_url}
              alt={movie.title}
            />
          )}
          <div>
            <p className="eyebrow">
              {movie.featured ? "FEATURED MOVIE" : "HYPERLINE MOVIES"}
            </p>
            <h1>{movie.title}</h1>
            <p className="muted">
              {movie.release_year}{" "}
              {movie.duration_minutes
                ? ` · ${movie.duration_minutes} minutes`
                : ""}
            </p>
            <div className="tags">
              {movie.genres.map((g) => (
                <span key={g.id}>{g.name}</span>
              ))}
            </div>
            <p className="synopsis">
              {movie.synopsis || "A new story awaits."}
            </p>
            {movie.playable ? (
              <Link
                className="button"
                href={
                  token
                    ? `/watch?id=${movie.id}`
                    : `/login?next=${encodeURIComponent(`/watch?id=${movie.id}`)}`
                }
              >
                {token ? "Watch now" : "Sign in to watch"}
              </Link>
            ) : (
              <p>Playback is currently unavailable.</p>
            )}
            {token && (
              <button className="secondary" disabled={busy} onClick={watchlist}>
                Add to watchlist
              </button>
            )}
            {message && <p role="status">{message}</p>}
          </div>
        </section>
      )}
    </main>
  );
}
