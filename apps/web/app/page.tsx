"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import {
  api,
  ContinueWatchingItem,
  errorText,
  Movie,
  Series,
} from "../lib/api";
import { ContinueWatchingRow, MovieRow, SeriesRow } from "./cards";
import { useSession } from "./session";
import Artwork from "./components/artwork";
import { ErrorState, Skeleton } from "./components/ui";
import { contentLang } from "./components/content-language";
type Home = {
  hero: Movie | null;
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
          {token && data.continue_watching.length > 0 && (
            <ContinueWatchingRow items={data.continue_watching} />
          )}
          <MovieRow title="Trending now" movies={data.trending} />
          <MovieRow title="Latest movies" movies={data.latest} />
          <SeriesRow
            title="Discover series"
            series={(data.series || []).filter(
              (s) => s.category !== "vertical",
            )}
          />
          <section className="vertical-banner">
            <div>
              <p className="eyebrow">A DIFFERENT PERSPECTIVE</p>
              <h2>
                Short episodes.
                <br />
                Stories that pull you in.
              </h2>
              <p className="muted">
                Discover vertical dramas, made for the way you watch.
              </p>
            </div>
            <span className="vertical-mark" aria-hidden="true">
              9<span>:</span>16
            </span>
          </section>
          <SeriesRow title="Vertical dramas" series={vertical} />
          <MovieRow title="Featured movies" movies={data.featured} />
        </>
      )}
    </main>
  );
}
