"use client";
import { useEffect, useRef, useState } from "react";
import { errorText } from "../../lib/api";
import {
  loadYouTubeAPI,
  YouTubePlayer,
  YouTubeProgressMedia,
  youtubeError,
} from "../../lib/youtube";
import { ProgressOwner, useWatchProgress } from "./use-movie-progress";

export default function YouTube({
  videoId,
  owner,
  token,
  onError,
  onNotice,
}: {
  videoId: string;
  owner: ProgressOwner;
  token: string;
  onError: (message: string) => void;
  onNotice: (message: string) => void;
}) {
  const hostRef = useRef<HTMLDivElement>(null);
  const mediaRef = useRef<YouTubeProgressMedia | null>(null);
  const [ready, setReady] = useState(0);
  const [loading, setLoading] = useState(true);
  useWatchProgress(mediaRef, owner, token, onNotice, ready);
  useEffect(() => {
    let disposed = false;
    let player: YouTubePlayer | undefined;
    const timeout = window.setTimeout(() => {
      if (!disposed)
        onError(
          "YouTube took too long to load. Check your connection and retry.",
        );
    }, 20000);
    loadYouTubeAPI()
      .then((YT) => {
        if (disposed || !hostRef.current) return;
        const mount = document.createElement("div");
        hostRef.current.appendChild(mount);
        player = new YT.Player(mount, {
          videoId,
          width: "100%",
          height: "100%",
          playerVars: {
            playsinline: 1,
            controls: 1,
            origin: window.location.origin,
          },
          events: {
            onReady: ({ target }) => {
              if (disposed) return;
              window.clearTimeout(timeout);
              target.getIframe().title = "YouTube player";
              target.getIframe().referrerPolicy =
                "strict-origin-when-cross-origin";
              mediaRef.current = new YouTubeProgressMedia(target);
              setReady((r) => r + 1);
              setLoading(false);
            },
            onStateChange: ({ data }) => {
              if (!disposed) mediaRef.current?.stateChanged(data);
            },
            onError: ({ data }) => {
              if (!disposed) onError(youtubeError(data));
            },
            onAutoplayBlocked: () => {
              if (!disposed)
                onNotice(
                  "Select Play in the YouTube player to start playback.",
                );
            },
          },
        });
      })
      .catch((error) => {
        if (!disposed) onError(errorText(error));
      });
    const host = hostRef.current;
    return () => {
      disposed = true;
      window.clearTimeout(timeout);
      mediaRef.current?.dispose();
      mediaRef.current = null;
      player?.destroy();
      host?.replaceChildren();
    };
  }, [videoId, token, onError, onNotice]);
  return (
    <>
      <div ref={hostRef} className="youtube-player" />
      {loading && (
        <p className="player-status" role="status">
          Loading YouTube…
        </p>
      )}
    </>
  );
}
