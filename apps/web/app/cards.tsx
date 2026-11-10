import Link from "next/link";
import {
  ContinueWatchingItem,
  isEpisodeRow,
  Movie,
  Series,
} from "../lib/api";
export function Cards({ movies }: { movies: Movie[] }) {
  return (
    <div className="grid">
      {movies.map((m) => (
        <Link
          className="card"
          href={`/movies/${encodeURIComponent(m.slug)}`}
          key={m.id}
        >
          {m.poster_url ? (
            <img className="poster" src={m.poster_url} alt="" loading="lazy" />
          ) : (
            <div className="poster-placeholder">HL</div>
          )}
          <div className="card-body">
            <h3>{m.title}</h3>
            <p className="muted">
              {m.release_year || "Movie"}
              {m.duration_minutes ? ` · ${m.duration_minutes} min` : ""}
            </p>
          </div>
        </Link>
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
  return (
    <section className="rail">
      <div className="rail-head">
        <h2>{title}</h2>
        <Link href="/movies">View all →</Link>
      </div>
      {movies.length ? (
        <Cards movies={movies} />
      ) : (
        <p className="muted">No titles available yet.</p>
      )}
    </section>
  );
}
export function SeriesCards({ series }: { series: Series[] }) {
  return (
    <div className="grid">
      {series.map((s) => (
        <Link
          className="card"
          href={`/series/${encodeURIComponent(s.slug)}`}
          key={s.id}
        >
          {s.poster_url ? (
            <img className="poster" src={s.poster_url} alt="" loading="lazy" />
          ) : (
            <div className="poster-placeholder">HL</div>
          )}
          <div className="card-body">
            <h3>{s.title}</h3>
            <p className="muted">
              {s.episode_count ? `${s.episode_count} episodes` : "Series"}
              {s.language ? ` · ${s.language}` : ""}
            </p>
          </div>
        </Link>
      ))}
    </div>
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
    <section className="rail">
      <div className="rail-head">
        <h2>{title}</h2>
        <Link href="/series">View all →</Link>
      </div>
      {series.length ? (
        <SeriesCards series={series} />
      ) : (
        <p className="muted">No series available yet.</p>
      )}
    </section>
  );
}
function percent(
  position: number | null | undefined,
  duration: number | null | undefined,
  fallbackMinutes: number | null | undefined,
): number {
  const total =
    duration && duration > 0 ? duration : fallbackMinutes ? fallbackMinutes * 60 : 0;
  if (!position || !total || position <= 0) return 0;
  return Math.min(100, Math.max(2, Math.round((position / total) * 100)));
}
export function ContinueWatchingRow({
  items,
}: {
  items: ContinueWatchingItem[];
}) {
  return (
    <section className="rail" aria-label="Continue watching">
      <div className="rail-head">
        <h2>Continue watching</h2>
      </div>
      <div className="grid">
        {items.map((row) =>
          isEpisodeRow(row) ? (
            <Link
              className="card"
              key={`ep-${row.episode.id}`}
              href={`/watch?episode=${row.episode.id}&series=${encodeURIComponent(row.series.slug)}&n=${row.episode.episode_number}`}
            >
              {row.episode.still_url ? (
                <img
                  className="poster"
                  src={row.episode.still_url}
                  alt=""
                  loading="lazy"
                />
              ) : row.series.poster_url ? (
                <img
                  className="poster"
                  src={row.series.poster_url}
                  alt=""
                  loading="lazy"
                />
              ) : (
                <div className="poster-placeholder">HL</div>
              )}
              <div className="card-body">
                <h3>{row.series.title}</h3>
                <p className="muted">
                  {row.episode.season_number
                    ? `S${row.episode.season_number} `
                    : ""}
                  E{row.episode.episode_number}
                  {row.episode.title ? ` · ${row.episode.title}` : ""}
                </p>
                {percent(row.position_seconds, row.duration_seconds, null) > 0 && (
                  <div
                    className="progress-track"
                    role="progressbar"
                    aria-valuenow={percent(row.position_seconds, row.duration_seconds, null)}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label="Watch progress"
                  >
                    <i
                      style={{
                        width: `${percent(row.position_seconds, row.duration_seconds, null)}%`,
                      }}
                    />
                  </div>
                )}
              </div>
            </Link>
          ) : (
            <Link
              className="card"
              key={`mv-${row.id}`}
              href={`/watch?id=${row.id}`}
            >
              {row.poster_url ? (
                <img className="poster" src={row.poster_url} alt="" loading="lazy" />
              ) : (
                <div className="poster-placeholder">HL</div>
              )}
              <div className="card-body">
                <h3>{row.title}</h3>
                <p className="muted">Movie{row.release_year ? ` · ${row.release_year}` : ""}</p>
                {percent(row.position_seconds, row.duration_seconds, row.duration_minutes) > 0 && (
                  <div
                    className="progress-track"
                    role="progressbar"
                    aria-valuenow={percent(row.position_seconds, row.duration_seconds, row.duration_minutes)}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label="Watch progress"
                  >
                    <i
                      style={{
                        width: `${percent(row.position_seconds, row.duration_seconds, row.duration_minutes)}%`,
                      }}
                    />
                  </div>
                )}
              </div>
            </Link>
          ),
        )}
      </div>
    </section>
  );
}
