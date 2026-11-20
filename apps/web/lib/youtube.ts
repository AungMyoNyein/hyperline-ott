import type { ProgressMedia } from "../app/watch/use-movie-progress";

export type YouTubePlayer = {
  getDuration(): number;
  getCurrentTime(): number;
  getPlayerState(): number;
  seekTo(seconds: number, allowSeekAhead: boolean): void;
  getIframe(): HTMLIFrameElement;
  destroy(): void;
};
type YouTubeAPI = {
  Player: new (
    element: HTMLElement,
    options: {
      videoId: string;
      width: string;
      height: string;
      playerVars: Record<string, string | number>;
      events: {
        onReady(event: { target: YouTubePlayer }): void;
        onStateChange(event: { data: number }): void;
        onError(event: { data: number }): void;
        onAutoplayBlocked(): void;
      };
    },
  ) => YouTubePlayer;
};
declare global {
  interface Window {
    YT?: YouTubeAPI;
    onYouTubeIframeAPIReady?: () => void;
  }
}
let loading: Promise<YouTubeAPI> | null = null;

export function loadYouTubeAPI(): Promise<YouTubeAPI> {
  if (window.YT?.Player) return Promise.resolve(window.YT);
  if (loading) return loading;
  loading = new Promise((resolve, reject) => {
    const script = document.createElement("script");
    const previous = window.onYouTubeIframeAPIReady;
    const cleanup = () => {
      window.clearTimeout(timeout);
      if (window.onYouTubeIframeAPIReady === ready)
        window.onYouTubeIframeAPIReady = previous;
    };
    const fail = () => {
      cleanup();
      script.remove();
      loading = null;
      reject(
        new Error(
          "YouTube could not be reached. Check your connection and retry.",
        ),
      );
    };
    const ready = () => {
      cleanup();
      try {
        previous?.();
      } finally {
        if (window.YT?.Player) resolve(window.YT);
        else fail();
      }
    };
    const timeout = window.setTimeout(fail, 15000);
    window.onYouTubeIframeAPIReady = ready;
    script.src = "https://www.youtube.com/iframe_api";
    script.async = true;
    script.referrerPolicy = "strict-origin-when-cross-origin";
    script.onerror = fail;
    document.head.appendChild(script);
  });
  return loading;
}

export function youtubeError(code: number): string {
  switch (code) {
    case 2:
      return "This YouTube video link is invalid. Please choose another title.";
    case 100:
      return "This YouTube video is private or no longer available.";
    case 101:
    case 150:
      return "The owner of this YouTube video does not allow embedded playback.";
    case 153:
      return "YouTube could not verify this site's identity. Please contact support.";
    case 5:
      return "YouTube could not play this video in your browser. Please retry or use another browser.";
    default:
      return "YouTube playback is unavailable. Check your connection and retry.";
  }
}

export class YouTubeProgressMedia extends EventTarget implements ProgressMedia {
  private timer: number;
  private hasMetadata = false;
  constructor(private player: YouTubePlayer) {
    super();
    this.timer = window.setInterval(() => {
      if (!this.hasMetadata && this.readyState) {
        this.hasMetadata = true;
        this.dispatchEvent(new Event("loadedmetadata"));
      }
      if (this.player.getPlayerState() === 1)
        this.dispatchEvent(new Event("timeupdate"));
    }, 1000);
  }
  get readyState() {
    return Number.isFinite(this.duration) && this.duration > 0 ? 1 : 0;
  }
  get duration() {
    return this.player.getDuration();
  }
  get currentTime() {
    return this.player.getCurrentTime();
  }
  set currentTime(value: number) {
    this.player.seekTo(value, true);
  }
  get ended() {
    return this.player.getPlayerState() === 0;
  }
  stateChanged(state: number) {
    if (state === 2) this.dispatchEvent(new Event("pause"));
    if (state === 0) this.dispatchEvent(new Event("ended"));
  }
  dispose() {
    window.clearInterval(this.timer);
  }
}
