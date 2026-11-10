import { RefObject, useEffect } from "react";
import { api, errorText } from "../../lib/api";
import { progressGate, Progress } from "../../lib/progress";

export type ProgressOwner =
  | { kind: "movie"; id: string }
  | { kind: "episode"; id: string };

/**
 * Shared progress engine for movies and episodes. Behavior mirrors the Phase 1
 * movie contract exactly: owner-scoped resume lookup, restore after metadata
 * loads, completed titles restart at zero, 15-second gated writes with
 * duplicate suppression, forced snapshots on pause/ended/hidden/pagehide/
 * cleanup, serialized requests with a queued latest forced snapshot.
 */
export function useWatchProgress(
  videoRef: RefObject<HTMLVideoElement | null>,
  owner: ProgressOwner | null,
  token: string,
  onError: (message: string) => void,
) {
  const ownerId = owner?.id ?? "";
  const ownerKind = owner?.kind ?? "movie";
  useEffect(() => {
    const video = videoRef.current;
    if (!video || !token || !owner || !/^\d+$/.test(ownerId)) return;
    const idKey = ownerKind === "episode" ? "episode_id" : "movie_id";
    let disposed = false,
      restored = false,
      pending = false,
      fetched = false;
    let resume: Progress | null = null,
      queued: Progress | null = null;
    const controller = new AbortController();
    const gate = progressGate();
    function restore() {
      if (!fetched || restored || video!.readyState < 1) return;
      restored = true;
      if (resume && !resume.completed && Number.isFinite(video!.duration))
        video!.currentTime = Math.min(
          resume.position_seconds,
          Math.max(0, video!.duration - 1),
        );
    }
    api<{ data: Progress[] }>(`/watch-progress?${idKey}=${ownerId}`, token, {
      signal: controller.signal,
    })
      .then((j) => {
        resume = j.data[0] || null;
        fetched = true;
        restore();
      })
      .catch((e) => {
        if (!disposed) {
          fetched = true;
          restore();
          onError("Resume position could not be loaded. " + errorText(e));
        }
      });
    async function send(value: Progress, force: boolean) {
      // Serialize requests so a slow periodic save cannot overwrite completion or a
      // later pause position. Retain the latest forced snapshot during a request.
      if (pending) {
        if (force) queued = value;
        return;
      }
      if (!gate.take(value, Date.now(), force)) return;
      pending = true;
      try {
        await api("/watch-progress", token, {
          method: "POST",
          body: JSON.stringify({ [idKey]: Number(ownerId), ...value }),
          keepalive: force,
        });
      } catch (e) {
        gate.failed();
        if (!disposed)
          onError("Watch progress could not be saved. " + errorText(e));
      } finally {
        pending = false;
        if (queued) {
          const next = queued;
          queued = null;
          void send(next, true);
        }
      }
    }
    function save(force = false, completed = false) {
      if (
        !restored ||
        !Number.isFinite(video!.duration) ||
        video!.duration <= 0
      )
        return;
      const duration = Math.ceil(video!.duration),
        position = Math.min(duration, Math.floor(video!.currentTime));
      if (position === 0 && !completed) return;
      void send(
        {
          position_seconds: position,
          duration_seconds: duration,
          completed: completed || video!.ended,
        },
        force,
      );
    }
    const tick = () => save(),
      pause = () => save(true),
      ended = () => save(true, true),
      hidden = () => {
        if (document.visibilityState === "hidden") save(true);
      };
    video.addEventListener("loadedmetadata", restore);
    video.addEventListener("timeupdate", tick);
    video.addEventListener("pause", pause);
    video.addEventListener("ended", ended);
    window.addEventListener("pagehide", pause);
    document.addEventListener("visibilitychange", hidden);
    return () => {
      save(true);
      disposed = true;
      controller.abort();
      video.removeEventListener("loadedmetadata", restore);
      video.removeEventListener("timeupdate", tick);
      video.removeEventListener("pause", pause);
      video.removeEventListener("ended", ended);
      window.removeEventListener("pagehide", pause);
      document.removeEventListener("visibilitychange", hidden);
    };
  }, [ownerId, ownerKind, token, videoRef, onError]);
}

/** Phase 1 compatibility wrapper. */
export function useMovieProgress(
  videoRef: RefObject<HTMLVideoElement | null>,
  movieId: string,
  token: string,
  onError: (message: string) => void,
) {
  useWatchProgress(
    videoRef,
    /^\d+$/.test(movieId) ? { kind: "movie", id: movieId } : null,
    token,
    onError,
  );
}
