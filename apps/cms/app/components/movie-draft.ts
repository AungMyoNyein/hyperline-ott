import { Source } from "../../lib/api";
export type Draft = {
  title: string;
  slug: string;
  synopsis: string;
  release_year: string;
  duration_minutes: string;
  poster_url: string;
  backdrop_url: string;
  published: boolean;
  featured: boolean;
  genres: string[];
  sources: Source[];
};
export const newSource = (): Source => ({
  label: "main",
  kind: "hls",
  url: "",
  quality: "",
  language: "",
  enabled: true,
  is_default: true,
});
export const blank = (): Draft => ({
  title: "",
  slug: "",
  synopsis: "",
  release_year: "",
  duration_minutes: "",
  poster_url: "",
  backdrop_url: "",
  published: false,
  featured: false,
  genres: [],
  sources: [newSource()],
});
