"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import {
  api,
  ContinueWatchingItem,
  errorText,
  Movie,
  Genre,
  Series,
} from "../lib/api";
import { ContinueWatchingRow, MovieRow, SeriesRow } from "./cards";
import { useSession } from "./session";
import Artwork from "./components/artwork";
import { ErrorState, Skeleton } from "./components/ui";
import { contentLang } from "./components/content-language";
type Home = {
  hero: Movie | null;
  section_order?: string[];
  genres?: Genre[];
  featured: Movie[];
  trending: Movie[];
  latest: Movie[];
  series?: Series[];
  continue_watching: ContinueWatchingItem[];
};
export default function HomePage() {
  const { token } = useSession();
  const [data, setData] = useState<Home | null>(null);
  const [vertical, setVertical] = useState<Series[]>([]);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const c = new AbortController();
    setError("");
    setData(null);
    Promise.all([
      api<{ data: Home }>("/home", token, { signal: c.signal }),
      api<{ data: Series[] }>("/series?category=vertical&per_page=12", "", {
        signal: c.signal,
      }),
    ])
      .then(([home, dramas]) => {
        setData(home.data);
        setVertical(dramas.data.filter((s) => s.category === "vertical"));
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      });
    return () => c.abort();
  }, [token, revision]);
  const hero = data?.hero?.published !== false ? data?.hero : null;
  return (
    <main className="home-page">
      {error ? (
        <ErrorState message={error} retry={() => setRevision((r) => r + 1)} />
      ) : !data ? (
        <Skeleton hero label="Loading your next story…" />
      ) : (
        <>
          <section className={`hero${hero ? "" : " hero-brand"}`}>
            {hero ? (
              <div className="hero-art">
                <Artwork
                  src={hero.backdrop_url}
                  title={hero.title}
                  shape="landscape"
                  priority
                />
              </div>
            ) : (
              <div className="hero-emblem" aria-hidden="true">
                H<span>╱</span>L
              </div>
            )}
            <div className="hero-inner">
              <p className="eyebrow">
                <span className="red-dash" />{" "}
                {hero
                  ? "THE HYPERLINE SPOTLIGHT"
                  : "WELCOME TO YOUR NEXT CHAPTER"}
              </p>
              <h1 lang={contentLang(hero?.title)}>
                {hero?.title || "Stories that stay with you."}
              </h1>
              {hero && (
                <p className="hero-meta">
                  {[
                    hero.release_year,
                    hero.duration_minutes
                      ? `${hero.duration_minutes} min`
                      : null,
                    ...hero.genres.map((g) => g.name),
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
              )}
              <p className="hero-synopsis" lang={contentLang(hero?.synopsis)}>
                {hero?.synopsis ||
                  "Big-screen stories. Small-screen escapes. A world of cinema and series, brought together by HyperLine."}
              </p>
              <div className="hero-actions">
                {hero?.playable && (
                  <Link className="button" href={`/watch?id=${hero.id}`}>
                    <span aria-hidden="true">▶</span> Watch now
                  </Link>
                )}
                <Link
                  className={`button ${hero?.playable ? "secondary glass" : ""}`}
                  href={
                    hero
                      ? `/movies/${encodeURIComponent(hero.slug)}`
                      : "/movies"
                  }
                >
                  {hero ? "More info" : "Explore movies"}{" "}
                  <span aria-hidden="true">↗</span>
                </Link>
                {!hero && (
                  <Link className="button secondary glass" href="/series">
                    Discover series
                  </Link>
                )}
              </div>
            </div>
            <div className="hero-bottom">
              <span>
                HYPERLINE <span className="muted">/ CURATED FOR YOU</span>
              </span>
              <span aria-hidden="true">
                01 <i /> SPOTLIGHT
              </span>
            </div>
          </section>
          <div className="home-intro">
            <span>YOUR WORLD. YOUR STORIES.</span>
            <span className="muted">Cinema, series & a little escape.</span>
          </div>
          {(
            data.section_order || [
              "continue",
              "trending",
              "latest",
              "genres",
              "series",
              "vertical",
              "featured",
            ]
          ).map((section) => {
            // Avoid repeating a complete row when the small catalog is identical.
            const signature = (movies: Movie[]) =>
              movies
                .map((m) => m.id)
                .sort((a, b) => a - b)
                .join(",");
            const duplicate = (movies: Movie[], prior: Movie[][]) =>
              prior.some((row) => signature(row) === signature(movies));
            const order = data.section_order || [
              "continue",
              "trending",
              "latest",
              "genres",
              "series",
              "vertical",
              "featured",
            ];
            const movieRows: Record<
              string,
              { title: string; movies: Movie[] }
            > = {
              trending: { title: "Trending now", movies: data.trending },
              latest: { title: "Latest movies", movies: data.latest },
              featured: { title: "Featured movies", movies: data.featured },
            };
            const row = movieRows[section];
            if (row) {
              const prior = order
                .slice(0, order.indexOf(section))
                .filter((key) => movieRows[key])
                .map((key) => movieRows[key].movies);
              return duplicate(row.movies, prior) ? null : (
                <MovieRow key={section} title={row.title} movies={row.movies} />
              );
            }
            if (section === "continue")
              return token && data.continue_watching.length ? (
                <ContinueWatchingRow
                  key={section}
                  items={data.continue_watching}
                />
              ) : null;
            if (section === "genres")
              return (data.genres || []).some((g) => !!g.movies_count) ? (
                <section
                  key={section}
                  className="genre-discovery"
                  aria-label="Movies by genre"
                >
                  <h2>Movies by genre</h2>
                  <div className="genre-links">
                    {data.genres
                      ?.filter((g) => !!g.movies_count)
                      .map((g) => (
                        <Link
                          key={g.id}
                          className="button secondary"
                          href={`/movies?genre=${encodeURIComponent(g.slug)}`}
                        >
                          {g.name}
                          {g.name_my && (
                            <span lang="my"> · {g.name_my}</span>
                          )}{" "}
                          <span className="muted">({g.movies_count})</span>
                        </Link>
                      ))}
                  </div>
                </section>
              ) : null;
            if (section === "series")
              return (
                <SeriesRow
                  key={section}
                  title="Discover series"
                  series={(data.series || []).filter(
                    (s) => s.category !== "vertical",
                  )}
                />
              );
            if (section === "vertical")
              return vertical.length ? (
                <SeriesRow
                  key={section}
                  title="Vertical dramas"
                  series={vertical}
                />
              ) : null;
            return null;
          })}
        </>
      )}
    </main>
  );
}
