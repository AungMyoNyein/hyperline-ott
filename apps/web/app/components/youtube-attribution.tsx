import type { YouTubeAttribution } from "../../lib/api";

export default function YouTubeAttributionView({
  attribution,
  compact = false,
}: {
  attribution?: YouTubeAttribution | null;
  compact?: boolean;
}) {
  if (!attribution) return null;
  const channel = attribution.channel_name;
  if (compact)
    return <p className="muted">YouTube{channel ? ` · ${channel}` : ""}</p>;
  return (
    <aside
      className="youtube-attribution"
      aria-label="YouTube source attribution"
    >
      <p>
        YouTube
        {channel && attribution.channel_url && (
          <>
            {" · "}
            <a
              href={attribution.channel_url}
              target="_blank"
              rel="noopener noreferrer"
            >
              {channel}
            </a>
          </>
        )}
      </p>
      {attribution.youtube_title && <p>{attribution.youtube_title}</p>}
      {attribution.playlist_url && (
        <a
          href={attribution.playlist_url}
          target="_blank"
          rel="noopener noreferrer"
        >
          Supplied YouTube playlist
        </a>
      )}
    </aside>
  );
}
