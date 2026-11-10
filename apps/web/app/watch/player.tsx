"use client";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import type HlsType from "hls.js";
import type { MediaPlayerClass } from "dashjs";
import { api, errorText, Series } from "../../lib/api";
import { useWatchProgress } from "./use-movie-progress";
import { useSession } from "../session";
type Playback = {
  url: string;
  session_id: string;
  expires_at: string;
  source: { kind: string } | null;
  credentials_required?: boolean;
  renew_after_seconds?: number | null;
};
/**
 * Converts an administrator-entered YouTube link (watch/short/embed/youtu.be)
 * into the privacy-moderate embed URL. Falls back to the raw URL, which the
 * iframe will refuse visibly rather than silently playing the wrong video.
 */
function toYouTubeEmbed(raw: string): string {
  try {
    const u = new URL(raw);
    const host = u.hostname.replace(/^www\./, "");
    let id: string | null = null;
    if (host === "youtu.be") id = u.pathname.slice(1).split("/")[0] || null;
    else if (host.endsWith("youtube.com")) {
      const m = u.pathname.match(/^\/(embed|shorts|live)\/([^/?#]+)/);
      if (m) id = m[2];
      else if (u.pathname.startsWith("/watch")) id = u.searchParams.get("v");
    }
    if (id && /^[A-Za-z0-9_-]{11}$/.test(id))
      return `https://www.youtube.com/embed/${id}?rel=0&modestbranding=1`;
  } catch {
    /* fall through */
  }
  return raw;
}
export default function Player({
  movieId,
  episodeId,
  seriesSlug,
  episodeNumber,
}: {
  movieId: string;
  episodeId?: string;
  seriesSlug?: string;
  episodeNumber?: string;
}) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const hlsRef = useRef<HlsType | null>(null);
  const dashRef = useRef<MediaPlayerClass | null>(null);
  const { token } = useSession();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [youtubeSrc, setYoutubeSrc] = useState<string | null>(null);
  const [levels, setLevels] = useState<{ height: number; bitrate: number }[]>(
    [],
  );
  const [quality, setQuality] = useState(-1);
  const [series, setSeries] = useState<Series | null>(null);
  const isEpisode = /^\d+$/.test(episodeId || "");
  const isMovie = /^\d+$/.test(movieId);
  const valid = isMovie || isEpisode;
  const watchTarget = movieId
    ? `/watch?id=${movieId}`
    : `/watch?episode=${episodeId}&series=${encodeURIComponent(seriesSlug || "")}&n=${episodeNumber || ""}`;
  const episodes = series?.episodes || [];
  const currentIndex = episodes.findIndex((ep) =>
    isEpisode
      ? ep.id === Number(episodeId)
      : ep.episode_number === Number(episodeNumber),
  );
  const prev = currentIndex > 0 ? episodes[currentIndex - 1] : null;
  const next =
    currentIndex >= 0 && currentIndex < episodes.length - 1
      ? episodes[currentIndex + 1]
      : null;
  const vertical = series?.category === "vertical";
  useWatchProgress(
    videoRef,
    isMovie
      ? { kind: "movie", id: movieId }
      : isEpisode
        ? { kind: "episode", id: episodeId || "" }
        : null,
    token,
    setError,
  );
  useEffect(() => {
    if (!seriesSlug) return;
    const c = new AbortController();
    api<{ data: Series }>(`/series/${encodeURIComponent(seriesSlug)}`, "", {
      signal: c.signal,
    })
      .then((j) => setSeries(j.data))
      .catch(() => {});
    return () => c.abort();
  }, [seriesSlug]);
  useEffect(() => {
    if (!token || !valid) return;
    const video = videoRef.current;
    if (!video) return;
    const controller = new AbortController();
    let disposed = false;
    let hls: HlsType | null = null;
    let dash: MediaPlayerClass | null = null;
    let renewal: number | undefined;
    let restorePosition: (() => void) | undefined;
    setLoading(true);
    setError("");
    setLevels([]);
    setQuality(-1);
    setYoutubeSrc(null);
    const timeout = window.setTimeout(() => {
      if (!disposed) {
        setLoading(false);
        setError("The stream took too long to load. Please retry.");
        hls?.destroy();
        window.clearTimeout(renewal);
        controller.abort();
      }
    }, 30000);
    function ready() {
      window.clearTimeout(timeout);
      setLoading(false);
    }
    function mediaError() {
      ready();
      setError(
        "The video could not be loaded. Check stream access, CDN CORS settings, format, and connection.",
      );
    }
    video.addEventListener("loadeddata", ready);
    video.addEventListener("error", mediaError);
    (async () => {
      try {
        const body = isMovie
          ? { movie_id: Number(movieId) }
          : { episode_id: Number(episodeId) };
        const j = await api<{ data: Playback }>("/playback/sessions", token, {
          method: "POST",
          credentials: "include",
          body: JSON.stringify(body),
          signal: controller.signal,
        });
        if (disposed) return;
        const playback = j.data;
        const url = new URL(playback.url);
        if (
          url.protocol !== "https:" &&
          !(
            process.env.NODE_ENV !== "production" &&
            url.hostname === "127.0.0.1"
          )
        )
          throw new Error("The playback URL is not secure.");
        if (playback.source?.kind === "youtube") {
          // Public embed: no signed renewal (the API returns renew_after=null),
          // no video element, and therefore no position reporting.
          ready();
          setYoutubeSrc(toYouTubeEmbed(playback.url));
          return;
        }
        video.crossOrigin = playback.credentials_required
          ? "use-credentials"
          : "anonymous";
        async function renew(session: Playback) {
          if (disposed || !session.renew_after_seconds) return;
          renewal = window.setTimeout(async () => {
            try {
              const result = await api<{ data: Playback }>(
                `/playback/sessions/${encodeURIComponent(session.session_id)}/renew`,
                token,
                {
                  method: "POST",
                  credentials: "include",
                  signal: controller.signal,
                },
              );
              if (!disposed) {
                const nextSession = result.data;
                if (nextSession.url !== session.url) {
                  const updated = new URL(nextSession.url);
                  if (updated.protocol !== "https:")
                    throw new Error("The renewed playback URL is not secure.");
                  const position = video?.currentTime || 0;
                  const playing = video ? !video.paused : false;
                  if (restorePosition)
                    video?.removeEventListener(
                      "loadedmetadata",
                      restorePosition,
                    );
                  restorePosition = () => {
                    if (video && Number.isFinite(position))
                      video.currentTime = position;
                    if (playing) void video?.play().catch(() => {});
                  };
                  video?.addEventListener("loadedmetadata", restorePosition, {
                    once: true,
                  });
                  if (hls) hls.loadSource(updated.href);
                  else if (dash) dash.attachSource(updated.href);
                  else if (video) {
                    video.src = updated.href;
                    video.load();
                  }
                }
                renew(nextSession);
              }
            } catch (e) {
              if (!disposed && !controller.signal.aborted) {
                video?.pause();
                hls?.stopLoad();
                setError(
                  "Playback authorization could not be renewed. " +
                    errorText(e),
                );
              }
            }
          }, session.renew_after_seconds * 1000);
        }
        renew(playback);
        if (playback.source?.kind === "mp4") {
          video.src = url.href;
          video.load();
          return;
        }
        if (playback.source?.kind === "dash") {
          const dashjs = await import("dashjs");
          if (disposed) return;
          dash = dashjs.MediaPlayer().create();
          dashRef.current = dash;
          dash.updateSettings({ debug: { logLevel: 0 } });
          dash.on(dashjs.MediaPlayer.events.ERROR, (event) => {
            if (disposed) return;
            const detail = (event as { error?: { code?: number } }).error;
            ready();
            window.clearTimeout(renewal);
            setError(
              `DASH playback failed (${detail?.code ?? "unknown"}). The manifest may be unavailable, expired, or blocked by CORS.`,
            );
            dash?.reset();
            dash = null;
            dashRef.current = null;
          });
          dash.on(dashjs.MediaPlayer.events.STREAM_INITIALIZED, () => {
            if (!disposed) ready();
          });
          // Signed DASH URLs ride the same renewal timer as HLS above.
          dash.initialize(video, url.href, false);
          return;
        }
        if (playback.source?.kind !== "hls")
          throw new Error(
            "This source format is not supported by this player. Please choose an HLS source.",
          );
        const Hls = (await import("hls.js")).default;
        if (disposed) return;
        if (!Hls.isSupported()) {
          if (video.canPlayType("application/vnd.apple.mpegurl")) {
            video.src = url.href;
            video.load();
            return;
          }
          throw new Error("HLS playback is not supported by this browser.");
        }
        hls = new Hls({
          enableWorker: true,
          xhrSetup: (xhr) => {
            xhr.withCredentials = !!playback.credentials_required;
          },
          fetchSetup: (context, init) =>
            new Request(context.url, {
              ...init,
              credentials: playback.credentials_required ? "include" : "omit",
            }),
        });
        hlsRef.current = hls;
        hls.on(Hls.Events.MANIFEST_PARSED, () => {
          if (!disposed)
            setLevels(
              hls!.levels.map((l) => ({
                height: l.height,
                bitrate: l.bitrate,
              })),
            );
        });
        let recovered = false;
        hls.on(Hls.Events.ERROR, (_event, data) => {
          if (disposed || !data.fatal) return;
          if (data.type === Hls.ErrorTypes.MEDIA_ERROR && !recovered) {
            recovered = true;
            hls?.recoverMediaError();
            return;
          }
          ready();
          window.clearTimeout(renewal);
          setError(
            data.type === Hls.ErrorTypes.NETWORK_ERROR
              ? "Unable to load this stream. The CDN may be unavailable, the URL may have expired, or browser CORS access may be blocked."
              : `Playback failed (${data.details}). Please retry.`,
          );
          hls?.destroy();
          hlsRef.current = null;
        });
        hls.loadSource(url.href);
        hls.attachMedia(video);
      } catch (e) {
        if (!disposed && !controller.signal.aborted) {
          ready();
          setError(errorText(e));
        }
      }
    })();
    return () => {
      disposed = true;
      controller.abort();
      window.clearTimeout(renewal);
      window.clearTimeout(timeout);
      hls?.destroy();
      hlsRef.current = null;
      dash?.reset();
      dash = null;
      dashRef.current = null;
      if (restorePosition)
        video.removeEventListener("loadedmetadata", restorePosition);
      video.removeEventListener("loadeddata", ready);
      video.removeEventListener("error", mediaError);
      video.pause();
      video.removeAttribute("src");
      video.load();
    };
  }, [movieId, episodeId, token, retry, valid, isMovie]);
  async function fullscreen() {
    try {
      const video = videoRef.current as
        (HTMLVideoElement & { webkitEnterFullscreen?: () => void }) | null;
      if (containerRef.current?.requestFullscreen)
        await containerRef.current.requestFullscreen();
      else if (video?.webkitEnterFullscreen) video.webkitEnterFullscreen();
      else setError("Use the video controls to enter full screen.");
    } catch {
      setError("Full screen is unavailable. Use the video controls.");
    }
  }
  if (!valid)
    return (
      <p>
        Select a title from the <Link href="/movies">catalog</Link> or a{" "}
        <Link href="/series">series</Link>.
      </p>
    );
  if (!token)
    return (
      <p>
        <Link
          className="button"
          href={`/login?next=${encodeURIComponent(watchTarget)}`}
        >
          Sign in to watch
        </Link>
      </p>
    );
  const epLink = (ep: { id: number; episode_number: number }) =>
    `/watch?episode=${ep.id}&series=${encodeURIComponent(seriesSlug || "")}&n=${ep.episode_number}`;
  return (
    <>
      {series && isEpisode && (
        <p className="eyebrow">
          {series.title}
          {currentIndex >= 0
            ? ` · ${
                episodes[currentIndex].season_number
                  ? `S${episodes[currentIndex].season_number} `
                  : ""
              }EPISODE ${episodes[currentIndex].episode_number}`
            : ""}
        </p>
      )}
      <div
        className={`player${vertical ? " player-vertical" : ""}`}
        ref={containerRef}
      >
        {youtubeSrc && (
          <iframe
            src={youtubeSrc}
            title="YouTube player"
            allow="accelerometer; autoplay; encrypted-media; picture-in-picture; web-share"
            allowFullScreen
            style={{
              width: "100%",
              aspectRatio: "16 / 9",
              border: 0,
              display: "block",
            }}
          />
        )}
        <video
          ref={videoRef}
          controls
          playsInline
          preload="metadata"
          crossOrigin="anonymous"
          aria-label={isEpisode ? "Episode player" : "Movie player"}
          style={youtubeSrc ? { display: "none" } : undefined}
        />
        {loading && !youtubeSrc && (
          <p className="player-status" role="status">
            Loading stream…
          </p>
        )}
      </div>
      <div className="player-tools">
        <button onClick={fullscreen}>Full screen</button>
        {levels.length > 0 && (
          <label>
            Quality
            <select
              aria-label="Quality"
              value={quality}
              onChange={(e) => {
                const level = Number(e.target.value);
                setQuality(level);
                if (hlsRef.current) hlsRef.current.currentLevel = level;
              }}
            >
              <option value={-1}>Auto (adaptive)</option>
              {levels.map((l, i) => (
                <option key={i} value={i}>
                  {l.height
                    ? `${l.height}p`
                    : `${Math.round(l.bitrate / 1000)} kbps`}
                </option>
              ))}
            </select>
          </label>
        )}
        <button className="secondary" onClick={() => setRetry((r) => r + 1)}>
          Reload stream
        </button>
      </div>
      {series && isEpisode && (
        <nav className="ep-nav" aria-label="Episode navigation">
          <Link
            className={`button secondary${prev ? "" : " disabled"}`}
            href={prev ? epLink(prev) : "#"}
            aria-disabled={!prev}
            tabIndex={prev ? 0 : -1}
          >
            ← Previous episode
          </Link>
          {seriesSlug && (
            <Link
              className="button secondary"
              href={`/series/${encodeURIComponent(seriesSlug)}`}
            >
              All episodes
            </Link>
          )}
          <Link
            className={`button${next ? "" : " secondary disabled"}`}
            href={next ? epLink(next) : "#"}
            aria-disabled={!next}
            tabIndex={next ? 0 : -1}
          >
            Next episode →
          </Link>
        </nav>
      )}
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
    </>
  );
}
