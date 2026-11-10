export type Genre = {
  id: number;
  name: string;
  slug: string;
  movies_count?: number;
};
export type Source = {
  label: string;
  kind: "hls" | "dash" | "mp4" | "youtube";
  url?: string;
  quality?: string | null;
  language?: string | null;
  is_default?: boolean;
  enabled?: boolean;
};
export type Movie = {
  id: number;
  title: string;
  slug: string;
  synopsis: string | null;
  poster_url: string | null;
  backdrop_url: string | null;
  release_year: number | null;
  duration_minutes: number | null;
  featured: boolean;
  published?: boolean;
  playable?: boolean;
  genres: Genre[];
  sources?: Source[];
};
export type Episode = {
  id: number;
  season_id?: number | null;
  season_number?: number | null;
  episode_number: number;
  title: string | null;
  synopsis: string | null;
  duration_seconds: number | null;
  still_url: string | null;
  published?: boolean;
  playable?: boolean;
  sources?: Source[];
};
export type Season = {
  id: number;
  season_number: number;
  title: string | null;
  synopsis: string | null;
  poster_url: string | null;
  backdrop_url: string | null;
  episode_count?: number | null;
  episodes?: Episode[];
};
export type Series = {
  id: number;
  type: "series";
  title: string;
  slug: string;
  synopsis: string | null;
  category: string | null;
  language: string | null;
  release_year: number | null;
  poster_url: string | null;
  backdrop_url: string | null;
  featured: boolean;
  published_at?: string | null;
  episode_count?: number;
  season_count?: number;
  seasons?: Season[];
  episodes?: Episode[];
};
export type ContinueWatchingMovie = Movie & {
  type?: "movie";
  position_seconds?: number;
  duration_seconds?: number | null;
  last_watched_at?: string;
};
export type ContinueWatchingEpisode = {
  type: "episode";
  id: number;
  episode: {
    id: number;
    episode_number: number;
    season_number: number | null;
    title: string | null;
    duration_seconds: number | null;
    still_url: string | null;
  };
  series: {
    id: number;
    slug: string;
    title: string;
    poster_url: string | null;
  };
  position_seconds: number;
  duration_seconds: number | null;
  last_watched_at: string;
};
export type ContinueWatchingItem =
  | ContinueWatchingMovie
  | ContinueWatchingEpisode;
export function isEpisodeRow(
  row: ContinueWatchingItem,
): row is ContinueWatchingEpisode {
  return row.type === "episode";
}
export type ProgressRow = {
  id: number;
  type: "movie" | "episode";
  movie?: Movie;
  episode?: {
    id: number;
    episode_number: number;
    season_number: number | null;
    title: string | null;
    duration_seconds: number | null;
    still_url: string | null;
    series: { id: number; slug: string; title: string; poster_url: string | null };
  };
  position_seconds: number;
  duration_seconds: number | null;
  completed: boolean;
  last_watched_at: string;
};
export type User = { id: number; name: string; email: string; roles: string[] };
export type Page<T> = {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
};
export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}
export async function api<T>(
  path: string,
  token = "",
  options: RequestInit = {},
): Promise<T> {
  const base = process.env.NEXT_PUBLIC_API_URL;
  if (!base) throw new Error("The API address is not configured.");
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  if (options.body) headers.set("Content-Type", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);
  const response = await fetch(base.replace(/\/$/, "") + path, {
    ...options,
    headers,
    cache: "no-store",
    credentials: options.credentials ?? "omit",
  });
  const data = await response.json().catch(() => null);
  if (response.status === 401 && token && typeof window !== "undefined")
    window.dispatchEvent(
      new CustomEvent("hyperline:expired", { detail: token }),
    );
  if (!response.ok)
    throw new ApiError(
      response.status,
      data?.message || `Request failed (${response.status}).`,
      data?.errors || {},
    );
  if (!data) throw new Error("The API returned an unreadable response.");
  return data as T;
}
export function errorText(error: unknown): string {
  if (error instanceof ApiError && Object.keys(error.errors).length)
    return Object.entries(error.errors)
      .map(([key, messages]) => `${key}: ${messages.join(" ")}`)
      .join("\n");
  return error instanceof Error
    ? error.message
    : "Something went wrong. Please retry.";
}
