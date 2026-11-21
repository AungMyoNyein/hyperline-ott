"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api, errorText, Movie, ProgressRow } from "../../../lib/api";
import { useSession } from "../../session";
import Artwork from "../../components/artwork";
import { ErrorState, Skeleton } from "../../components/ui";
import YouTubeAttributionView from "../../components/youtube-attribution";
export default function Detail({ slug }: { slug: string }) {
  const { token } = useSession();
  const [position, setPosition] = useState(0);
  useEffect(() => {
    setPosition(0);
    if (!token) return;
    const c = new AbortController();
    api<{ data: ProgressRow[] }>("/watch-progress", token, { signal: c.signal })
      .then((j) => {
        const row = j.data.find(
          (r) => r.type === "movie" && r.movie?.slug === slug && !r.completed,
        );
        if (row) setPosition(row.position_seconds);
      })
      .catch(() => {});
    return () => c.abort();
  }, [token, slug]);
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
        <ErrorState message={error} />
      ) : !movie ? (
        <Skeleton label="Loading movie…" />
      ) : (
        <section className="detail-hero">
          <div className="detail-backdrop">
            <Artwork
              src={movie.backdrop_url}
              title={movie.title}
              shape="landscape"
              priority
            />
          </div>
          <div className="detail-hero-inner">
            <Artwork src={movie.poster_url} title={movie.title} />
            <div className="detail-info">
              <p className="eyebrow">
                {movie.featured ? "FEATURED MOVIE" : "HYPERLINE MOVIES"}
              </p>
              <h1>{movie.title}</h1>
              <YouTubeAttributionView attribution={movie.youtube} />
              <p className="muted">
                {movie.release_year}{" "}
                {movie.duration_minutes
                  ? ` · ${movie.duration_minutes} minutes`
                  : ""}
              </p>
              <div className="tags">
                {movie.genres.map((g) => (
                  <Link
                    key={g.id}
                    href={`/movies?genre=${encodeURIComponent(g.slug)}`}
                  >
                    {g.name}
                  </Link>
                ))}
              </div>
              <p className="synopsis">
                {movie.synopsis || "A new story awaits."}
              </p>
              <div className="detail-actions">
                {movie.playable ? (
                  <Link
                    className="button"
                    href={
                      token
                        ? `/watch?id=${movie.id}`
                        : `/login?next=${encodeURIComponent(`/watch?id=${movie.id}`)}`
                    }
                  >
                    {token
                      ? position > 0
                        ? "Resume"
                        : "Watch now"
                      : "Sign in to watch"}
                  </Link>
                ) : (
                  <p>Playback is currently unavailable.</p>
                )}
                {token && (
                  <button
                    className="secondary"
                    disabled={busy}
                    onClick={watchlist}
                  >
                    Add to watchlist
                  </button>
                )}
              </div>
              {message && (
                <p className="toast" role="status">
                  {message}
                </p>
              )}
            </div>
          </div>
        </section>
      )}
    </main>
  );
}
