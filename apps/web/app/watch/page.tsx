import Link from "next/link";
import Player from "./player";
export default async function Watch({
  searchParams,
}: {
  searchParams: Promise<{
    id?: string;
    episode?: string;
    series?: string;
    n?: string;
  }>;
}) {
  const p = await searchParams;
  return (
    <main className="watch-page">
      <div className="watch-head">
        <div>
          <p className="eyebrow">NOW SHOWING</p>
          <h1>The best seat is yours.</h1>
        </div>
        <Link
          href={
            p.series ? `/series/${encodeURIComponent(p.series)}` : "/movies"
          }
        >
          ← Back to browsing
        </Link>
      </div>
      <Player
        key={p.id ? `movie-${p.id}` : `episode-${p.episode}`}
        movieId={p.id || ""}
        episodeId={p.episode || ""}
        seriesSlug={p.series || ""}
        episodeNumber={p.n || ""}
      />
    </main>
  );
}
