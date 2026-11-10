"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { api, ContinueWatchingItem, errorText, Movie, Series } from "../lib/api";
import { ContinueWatchingRow, MovieRow, SeriesRow } from "./cards";
import { useSession } from "./session";
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
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  useEffect(() => {
    const c = new AbortController();
    setError("");
    setData(null);
    api<{ data: Home }>("/home", token, { signal: c.signal })
      .then((j) => setData(j.data))
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      });
    return () => c.abort();
  }, [token, revision]);
  return (
    <main>
      {error ? (
        <div role="alert" className="error">
          {error}
          <button onClick={() => setRevision((r) => r + 1)}>Retry</button>
        </div>
      ) : !data ? (
        <p role="status">Loading your next story…</p>
      ) : (
        <>
          <section
            className="hero"
            style={
              data.hero?.backdrop_url
                ? {
                    backgroundImage: `linear-gradient(90deg, #080808ee, #08080866), url(${JSON.stringify(data.hero.backdrop_url)})`,
                  }
                : undefined
            }
          >
            <div className="hero-inner">
              <p className="eyebrow">THE HYPERLINE SPOTLIGHT</p>
              <h1>{data.hero?.title || "Stories that stay with you."}</h1>
              <p>
                {data.hero?.synopsis ||
                  "Explore a world of cinema. Find your next favorite."}
              </p>
              <Link
                className="button"
                href={data.hero ? `/movies/${data.hero.slug}` : "/movies"}
              >
                {data.hero ? "Explore movie" : "Explore movies"}
              </Link>
            </div>
          </section>
          {data.continue_watching.length > 0 && (
            <ContinueWatchingRow items={data.continue_watching} />
          )}
          <MovieRow title="Featured movies" movies={data.featured} />
          {!!data.series?.length && (
            <SeriesRow title="Series" series={data.series} />
          )}
          <MovieRow title="Trending now" movies={data.trending} />
          <MovieRow title="Recently added" movies={data.latest} />
        </>
      )}
    </main>
  );
}
