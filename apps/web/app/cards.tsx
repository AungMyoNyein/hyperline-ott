import Link from "next/link";
import { ContinueWatchingItem, isEpisodeRow, Movie, Series } from "../lib/api";
import Artwork from "./components/artwork";
import Rail from "./components/rail";
import { Progress } from "./components/ui";
function MovieCard({ movie: m }: { movie: Movie }) {
  return (
    <article className="card">
      <Link
        href={`/movies/${encodeURIComponent(m.slug)}`}
        className="card-main"
        aria-label={`${m.title} — details`}
      >
        <div className="card-image">
          <Artwork src={m.poster_url} title={m.title} />
          <span className="card-type">Movie</span>
          <span className="card-reveal" aria-hidden="true">
            Explore title ↗
          </span>
        </div>
        <div className="card-body">
          <h3>{m.title}</h3>
          <p className="muted">
            {[
              m.release_year,
              m.duration_minutes ? `${m.duration_minutes} min` : null,
              m.genres?.[0]?.name,
            ]
              .filter(Boolean)
              .join(" · ") || "Movie"}
          </p>
        </div>
      </Link>
      <div className="card-actions">
        {m.playable && (
          <Link href={`/watch?id=${m.id}`}>
            Watch <span aria-hidden="true">▷</span>
          </Link>
        )}
        <Link href={`/movies/${encodeURIComponent(m.slug)}`}>
          Details <span aria-hidden="true">↗</span>
        </Link>
      </div>
    </article>
  );
}
function SeriesCard({ series: s }: { series: Series }) {
  return (
    <article
      className={`card${s.category === "vertical" ? " card-vertical" : ""}`}
    >
      <Link
        href={`/series/${encodeURIComponent(s.slug)}`}
        className="card-main"
        aria-label={`${s.title} — details`}
      >
        <div className="card-image">
          <Artwork
            src={s.poster_url}
            title={s.title}
            shape={s.category === "vertical" ? "vertical" : "poster"}
          />
          <span className="card-type">
            {s.category === "vertical" ? "Vertical drama" : "Series"}
          </span>
          <span className="card-reveal" aria-hidden="true">
            Discover the series ↗
          </span>
        </div>
        <div className="card-body">
          <h3>{s.title}</h3>
          <p className="muted">
            {[
              s.episode_count != null ? `${s.episode_count} episodes` : null,
              s.release_year,
              s.language,
            ]
              .filter(Boolean)
              .join(" · ") || "Series"}
          </p>
        </div>
      </Link>
      <div className="card-actions">
        <Link href={`/series/${encodeURIComponent(s.slug)}`}>
          Episodes <span aria-hidden="true">▷</span>
        </Link>
      </div>
    </article>
  );
}
export function Cards({ movies }: { movies: Movie[] }) {
  return (
    <div className="grid">
      {movies
        .filter((m) => m.published !== false)
        .map((m) => (
          <MovieCard key={m.id} movie={m} />
        ))}
    </div>
  );
}
export function SeriesCards({ series }: { series: Series[] }) {
  return (
    <div className="grid">
      {series.map((s) => (
        <SeriesCard key={s.id} series={s} />
      ))}
    </div>
  );
}
export function MovieRow({
  title,
  movies,
}: {
  title: string;
  movies: Movie[];
}) {
  const rows = movies.filter((m) => m.published !== false);
  return (
    <Rail title={title} href="/movies" empty={!rows.length}>
      {rows.length ? (
        rows.map((m) => <MovieCard key={m.id} movie={m} />)
      ) : (
        <p className="muted">New stories will appear here when published.</p>
      )}
    </Rail>
  );
}
export function SeriesRow({
  title,
  series,
}: {
  title: string;
  series: Series[];
}) {
  return (
    <Rail title={title} href="/series" empty={!series.length}>
      {series.length ? (
        series.map((s) => <SeriesCard key={s.id} series={s} />)
      ) : (
        <p className="muted">There are no published series here yet.</p>
      )}
    </Rail>
  );
}
export function ContinueWatchingRow({
  items,
}: {
  items: ContinueWatchingItem[];
}) {
  return (
    <Rail title="Continue watching" label="Continue watching">
      {items.map((row) => {
        const ep = isEpisodeRow(row);
        const title = ep ? row.series.title : row.title;
        const subtitle = ep
          ? `${row.episode.season_number != null ? `S${row.episode.season_number} · ` : ""}E${row.episode.episode_number}${row.episode.title ? ` · ${row.episode.title}` : ""}`
          : "Movie";
        const href = ep
          ? `/watch?episode=${row.episode.id}&series=${encodeURIComponent(row.series.slug)}&n=${row.episode.episode_number}`
          : `/watch?id=${row.id}`;
        return (
          <Link
            key={`${ep ? "ep" : "mv"}-${row.id}`}
            className="card continue-card"
            href={href}
          >
            <Artwork
              src={
                ep
                  ? row.episode.still_url || row.series.poster_url
                  : row.backdrop_url || row.poster_url
              }
              title={title}
              shape="landscape"
            />
            <Progress
              position={row.position_seconds}
              duration={
                row.duration_seconds ||
                (!ep && row.duration_minutes ? row.duration_minutes * 60 : null)
              }
              label={`${title} watch progress`}
            />
            <div className="card-body">
              <span className="resume-icon" aria-hidden="true">
                ▷
              </span>
              <h3>{title}</h3>
              <p className="muted">{subtitle}</p>
              {row.position_seconds != null && (
                <small className="muted">
                  Resume at {Math.floor(row.position_seconds / 60)}:
                  {String(Math.floor(row.position_seconds % 60)).padStart(
                    2,
                    "0",
                  )}
                </small>
              )}
            </div>
          </Link>
        );
      })}
    </Rail>
  );
}
