"use client";
import { FormEvent, useEffect, useMemo, useState } from "react";
import {
  api,
  ApiError,
  errorText,
  Episode,
  Page,
  Season,
  Series,
  Source,
} from "../../lib/api";

type Props = {
  token: string;
};
type SeriesDraft = {
  title: string;
  slug: string;
  synopsis: string;
  category: string;
  language: string;
  release_year: string;
  poster_url: string;
  backdrop_url: string;
  featured: boolean;
  published: boolean;
};
type SeasonDraft = {
  season_number: string;
  title: string;
  synopsis: string;
  poster_url: string;
  backdrop_url: string;
  published: boolean;
};
type EpisodeDraft = {
  episode_number: string;
  title: string;
  synopsis: string;
  duration_seconds: string;
  still_url: string;
  published: boolean;
  sources: Source[];
};
const blankSeries = (): SeriesDraft => ({
  title: "",
  slug: "",
  synopsis: "",
  category: "",
  language: "",
  release_year: "",
  poster_url: "",
  backdrop_url: "",
  featured: false,
  published: false,
});
const blankSeason = (nextNumber: number): SeasonDraft => ({
  season_number: String(nextNumber),
  title: "",
  synopsis: "",
  poster_url: "",
  backdrop_url: "",
  published: true,
});
const newEpisodeSource = (): Source => ({
  label: "main",
  kind: "hls",
  url: "",
  quality: "",
  language: "",
  enabled: true,
  is_default: true,
});
const blankEpisode = (nextNumber: number): EpisodeDraft => ({
  episode_number: String(nextNumber),
  title: "",
  synopsis: "",
  duration_seconds: "",
  still_url: "",
  published: false,
  sources: [newEpisodeSource()],
});
function FieldError({
  name,
  errors,
}: {
  name: string;
  errors: Record<string, string[]>;
}) {
  return errors[name] ? (
    <span className="field-error" id={`error-${name}`}>
      {errors[name].join(" ")}
    </span>
  ) : null;
}
function seasonName(seasonNumber: number, title: string | null): string {
  return seasonNumber === 0
    ? title
      ? `Specials — ${title}`
      : "Specials"
    : title
      ? `Season ${seasonNumber} — ${title}`
      : `Season ${seasonNumber}`;
}
/**
 * Series administration: library with search/filters/pagination, series
 * editor, season manager and episode editor. All writes go through the
 * nested admin endpoints; mismatched identifiers 404 server-side.
 */
export default function SeriesLibrary({ token }: Props) {
  const [seriesList, setSeriesList] = useState<Series[]>([]);
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [filters, setFilters] = useState({ q: "", published: "", category: "" });
  const [query, setQuery] = useState("");
  const [expanded, setExpanded] = useState<Series | null>(null);
  const [seriesForm, setSeriesForm] = useState<
    { editingId: number | null; draft: SeriesDraft; clean: string } | null
  >(null);
  const [seasonForm, setSeasonForm] = useState<
    { seriesId: number; editingId: number | null; draft: SeasonDraft; clean: string } | null
  >(null);
  const [episodeForm, setEpisodeForm] = useState<
    { seriesId: number; seasonId: number; editingId: number | null; draft: EpisodeDraft; clean: string } | null
  >(null);
  const dirty = useMemo(() => {
    for (const form of [seriesForm, seasonForm, episodeForm]) {
      if (form && JSON.stringify(form.draft) !== form.clean) return true;
    }
    return false;
  }, [seriesForm, seasonForm, episodeForm]);
  useEffect(() => {
    if (!dirty) return;
    const leave = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", leave);
    return () => window.removeEventListener("beforeunload", leave);
  }, [dirty]);
  function discardForms(): boolean {
    return !dirty || window.confirm("Discard unsaved series/season/episode changes?");
  }
  function fail(e: unknown) {
    if (e instanceof ApiError) setFieldErrors(e.errors);
    setError(errorText(e));
  }
  async function run(task: () => Promise<void>) {
    setBusy(true);
    setError("");
    setFieldErrors({});
    try {
      await task();
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  useEffect(() => {
    if (!token) {
      setSeriesList([]);
      setExpanded(null);
      return;
    }
    const c = new AbortController();
    setLoading(true);
    setError("");
    api<Page<Series>>(`/admin/series?page=${page}&per_page=25&${query}`, token, {
      signal: c.signal,
    })
      .then((j) => {
        setSeriesList(j.data);
        setLast(j.meta.last_page);
      })
      .catch((e) => {
        if (!c.signal.aborted) fail(e);
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, page, query]);
  async function reloadList() {
    const j = await api<Page<Series>>(`/admin/series?page=${page}&per_page=25&${query}`, token);
    setSeriesList(j.data);
    setLast(j.meta.last_page);
  }
  async function reloadExpanded(seriesId: number) {
    const j = await api<{ data: Series }>(`/admin/series/${seriesId}`, token);
    setExpanded(j.data);
    return j.data;
  }
  async function expand(s: Series) {
    if (expanded?.id === s.id) {
      if (!discardForms()) return;
      setExpanded(null);
      closeForms();
      return;
    }
    if (!discardForms()) return;
    closeForms();
    await run(async () => {
      await reloadExpanded(s.id);
    });
  }
  function closeForms() {
    setSeriesForm(null);
    setSeasonForm(null);
    setEpisodeForm(null);
    setFieldErrors({});
  }
  async function toggleSeriesPublish(s: Series) {
    await run(async () => {
      await api(`/admin/series/${s.id}`, token, {
        method: "PATCH",
        body: JSON.stringify({ published: !s.published }),
      });
      await reloadList();
      if (expanded?.id === s.id) await reloadExpanded(s.id);
      setMessage(s.published ? "Series unpublished." : "Series published.");
    });
  }
  async function removeSeries(s: Series) {
    const seasons = s.season_count ?? 0;
    const episodes = s.episode_count ?? 0;
    if (
      !window.confirm(
        `Delete “${s.title}”? This permanently removes ${seasons} season(s) and ${episodes} episode(s) with their sources and viewer progress.`,
      )
    )
      return;
    await run(async () => {
      await api(`/admin/series/${s.id}`, token, { method: "DELETE" });
      if (expanded?.id === s.id) {
        setExpanded(null);
        closeForms();
      }
      if (seriesList.length === 1 && page > 1) setPage(page - 1);
      else await reloadList();
      setMessage("Series deleted.");
    });
  }
  function seriesPayload(d: SeriesDraft) {
    return {
      title: d.title,
      slug: d.slug || null,
      synopsis: d.synopsis || null,
      category: d.category || null,
      language: d.language || null,
      release_year: d.release_year ? Number(d.release_year) : null,
      poster_url: d.poster_url || null,
      backdrop_url: d.backdrop_url || null,
      featured: d.featured,
      published: d.published,
    };
  }
  async function saveSeries(e: FormEvent) {
    e.preventDefault();
    if (!seriesForm) return;
    const { editingId, draft } = seriesForm;
    await run(async () => {
      const j = await api<{ data: Series }>(
        editingId ? `/admin/series/${editingId}` : "/admin/series",
        token,
        {
          method: editingId ? "PATCH" : "POST",
          body: JSON.stringify(seriesPayload(draft)),
        },
      );
      setSeriesForm(null);
      setFieldErrors({});
      await reloadList();
      if (editingId && expanded?.id === editingId) await reloadExpanded(editingId);
      else if (!editingId) await reloadExpanded(j.data.id);
      setMessage("Series saved.");
    });
  }
  async function saveSeason(e: FormEvent) {
    e.preventDefault();
    if (!seasonForm) return;
    const { seriesId, editingId, draft } = seasonForm;
    await run(async () => {
      await api(
        editingId
          ? `/admin/series/${seriesId}/seasons/${editingId}`
          : `/admin/series/${seriesId}/seasons`,
        token,
        {
          method: editingId ? "PATCH" : "POST",
          body: JSON.stringify({
            season_number: Number(draft.season_number),
            title: draft.title || null,
            synopsis: draft.synopsis || null,
            poster_url: draft.poster_url || null,
            backdrop_url: draft.backdrop_url || null,
            published: draft.published,
          }),
        },
      );
      setSeasonForm(null);
      setFieldErrors({});
      await reloadExpanded(seriesId);
      await reloadList();
      setMessage("Season saved.");
    });
  }
  async function toggleSeasonPublish(s: Series, season: Season) {
    await run(async () => {
      await api(`/admin/series/${s.id}/seasons/${season.id}`, token, {
        method: "PATCH",
        body: JSON.stringify({ published: !season.published }),
      });
      await reloadExpanded(s.id);
      setMessage(season.published ? "Season unpublished." : "Season published.");
    });
  }
  async function removeSeason(s: Series, season: Season) {
    const count = season.episode_count ?? season.episodes?.length ?? 0;
    if (
      !window.confirm(
        `Delete ${seasonName(season.season_number, season.title)}? This permanently removes ${count} episode(s) with their sources and viewer progress.`,
      )
    )
      return;
    await run(async () => {
      await api(`/admin/series/${s.id}/seasons/${season.id}`, token, {
        method: "DELETE",
      });
      if (episodeForm?.editingId && season.episodes?.some((ep) => ep.id === episodeForm.editingId))
        setEpisodeForm(null);
      await reloadExpanded(s.id);
      await reloadList();
      setMessage("Season deleted.");
    });
  }
  function episodePayload(d: EpisodeDraft) {
    return {
      episode_number: Number(d.episode_number),
      title: d.title || null,
      synopsis: d.synopsis || null,
      duration_seconds: d.duration_seconds ? Number(d.duration_seconds) : null,
      still_url: d.still_url || null,
      published: d.published,
      sources: d.sources.map((src) => ({
        label: src.label,
        kind: src.kind,
        url: src.url,
        quality: src.quality || null,
        language: src.language || null,
        enabled: !!src.enabled,
        is_default: !!src.is_default,
      })),
    };
  }
  async function saveEpisode(e: FormEvent) {
    e.preventDefault();
    if (!episodeForm) return;
    const { seriesId, seasonId, editingId, draft } = episodeForm;
    await run(async () => {
      await api(
        editingId
          ? `/admin/series/${seriesId}/seasons/${seasonId}/episodes/${editingId}`
          : `/admin/series/${seriesId}/seasons/${seasonId}/episodes`,
        token,
        {
          method: editingId ? "PATCH" : "POST",
          body: JSON.stringify(episodePayload(draft)),
        },
      );
      setEpisodeForm(null);
      setFieldErrors({});
      await reloadExpanded(seriesId);
      await reloadList();
      setMessage("Episode saved.");
    });
  }
  async function toggleEpisodePublish(s: Series, season: Season, ep: Episode) {
    await run(async () => {
      await api(`/admin/series/${s.id}/seasons/${season.id}/episodes/${ep.id}`, token, {
        method: "PATCH",
        body: JSON.stringify({ published: !ep.published }),
      });
      await reloadExpanded(s.id);
      await reloadList();
      setMessage(ep.published ? "Episode unpublished." : "Episode published.");
    });
  }
  async function removeEpisode(s: Series, season: Season, ep: Episode) {
    if (
      !window.confirm(
        `Delete episode ${ep.episode_number}${ep.title ? ` “${ep.title}”` : ""}? This permanently removes its sources and viewer progress.`,
      )
    )
      return;
    await run(async () => {
      await api(`/admin/series/${s.id}/seasons/${season.id}/episodes/${ep.id}`, token, {
        method: "DELETE",
      });
      if (episodeForm?.editingId === ep.id) setEpisodeForm(null);
      await reloadExpanded(s.id);
      await reloadList();
      setMessage("Episode deleted.");
    });
  }
  function openEpisodeEditor(s: Series, season: Season, ep?: Episode) {
    if (!discardForms()) return;
    setFieldErrors({});
    if (ep) {
      const draft: EpisodeDraft = {
        episode_number: String(ep.episode_number),
        title: ep.title || "",
        synopsis: ep.synopsis || "",
        duration_seconds: ep.duration_seconds ? String(ep.duration_seconds) : "",
        still_url: ep.still_url || "",
        published: !!ep.published,
        sources: (ep.sources?.length ? ep.sources : [newEpisodeSource()]).map((src) => ({ ...src })),
      };
      setEpisodeForm({ seriesId: s.id, seasonId: season.id, editingId: ep.id, draft, clean: JSON.stringify(draft) });
    } else {
      const next = Math.max(0, ...(season.episodes || []).map((x) => x.episode_number)) + 1;
      const draft = blankEpisode(next);
      setEpisodeForm({ seriesId: s.id, seasonId: season.id, editingId: null, draft, clean: JSON.stringify(draft) });
    }
    setSeasonForm(null);
    setSeriesForm(null);
  }
  function patchEpisode(patch: Partial<EpisodeDraft>) {
    setEpisodeForm((f) => (f ? { ...f, draft: { ...f.draft, ...patch } } : f));
  }
  function patchEpisodeSource(index: number, patch: Partial<Source>) {
    setEpisodeForm((f) =>
      f
        ? {
            ...f,
            draft: {
              ...f.draft,
              sources: f.draft.sources.map((src, i) =>
                i === index ? { ...src, ...patch } : patch.is_default ? { ...src, is_default: false } : src,
              ),
            },
          }
        : f,
    );
  }
  return (
    <section aria-label="Series library">
      <div className="section-row">
        <h2>Series library</h2>
        <button
          type="button"
          disabled={busy || loading}
          onClick={() => {
            if (!discardForms()) return;
            closeForms();
            const draft = blankSeries();
            setSeriesForm({ editingId: null, draft, clean: JSON.stringify(draft) });
            setExpanded(null);
          }}
        >
          New series
        </button>
      </div>
      <p className="muted">
        Create series here or import them with <code>hyperline:import-series</code>. Seasons and
        episodes are managed inline; deletion cascades and asks for confirmation.
      </p>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      {message && <p role="status">{message}</p>}
      <form
        className="filters"
        aria-label="Series filters"
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setQuery(
            new URLSearchParams(
              Object.entries(filters).filter(([, v]) => v),
            ).toString(),
          );
        }}
      >
        <label>
          Search series
          <input
            aria-label="Search series"
            type="search"
            maxLength={120}
            value={filters.q}
            onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))}
          />
        </label>
        <label>
          Status
          <select
            aria-label="Series status filter"
            value={filters.published}
            onChange={(e) => setFilters((f) => ({ ...f, published: e.target.value }))}
          >
            <option value="">All</option>
            <option value="1">Published</option>
            <option value="0">Draft</option>
          </select>
        </label>
        <label>
          Category
          <input
            aria-label="Category filter"
            maxLength={60}
            placeholder="e.g. vertical"
            value={filters.category}
            onChange={(e) => setFilters((f) => ({ ...f, category: e.target.value }))}
          />
        </label>
        <button type="submit" disabled={loading || busy}>
          Apply series filters
        </button>
      </form>
      {seriesForm && (
        <form onSubmit={saveSeries} aria-label={seriesForm.editingId ? "Edit series" : "Create series"}>
          <fieldset disabled={busy}>
            <legend>{seriesForm.editingId ? "Edit series" : "Create series"}</legend>
            <div className="fields">
              {(
                [
                  ["title", "Title", "text", true],
                  ["slug", "Slug (optional on create)", "text", false],
                  ["category", "Category (e.g. vertical)", "text", false],
                  ["language", "Language", "text", false],
                  ["release_year", "Release year", "number", false],
                  ["poster_url", "Poster HTTPS URL", "url", false],
                  ["backdrop_url", "Backdrop HTTPS URL", "url", false],
                ] as const
              ).map(([key, label, type, required]) => (
                <label key={key}>
                  {label}
                  <input
                    aria-label={label}
                    aria-invalid={!!fieldErrors[key]}
                    aria-describedby={fieldErrors[key] ? `error-${key}` : undefined}
                    type={type}
                    required={required}
                    value={seriesForm.draft[key]}
                    onChange={(e) =>
                      setSeriesForm((f) =>
                        f ? { ...f, draft: { ...f.draft, [key]: e.target.value } } : f,
                      )
                    }
                  />
                  <FieldError name={key} errors={fieldErrors} />
                </label>
              ))}
            </div>
            <label htmlFor="series-synopsis">Synopsis</label>
            <textarea
              id="series-synopsis"
              rows={3}
              value={seriesForm.draft.synopsis}
              onChange={(e) =>
                setSeriesForm((f) => (f ? { ...f, draft: { ...f.draft, synopsis: e.target.value } } : f))
              }
            />
            <FieldError name="synopsis" errors={fieldErrors} />
            <div className="checks">
              <label>
                <input
                  type="checkbox"
                  checked={seriesForm.draft.published}
                  onChange={(e) =>
                    setSeriesForm((f) =>
                      f ? { ...f, draft: { ...f.draft, published: e.target.checked } } : f,
                    )
                  }
                />{" "}
                Published
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={seriesForm.draft.featured}
                  onChange={(e) =>
                    setSeriesForm((f) =>
                      f ? { ...f, draft: { ...f.draft, featured: e.target.checked } } : f,
                    )
                  }
                />{" "}
                Featured
              </label>
            </div>
            <div>
              <button type="submit">{busy ? "Saving…" : "Save series"}</button>
              <button type="button" className="secondary" onClick={() => discardForms() && closeForms()}>
                Cancel
              </button>
            </div>
          </fieldset>
        </form>
      )}
      {loading && <p role="status">Loading series…</p>}
      {!loading && !seriesList.length && <p>No series match this view.</p>}
      <div className="library">
        {seriesList.map((s) => (
          <article key={s.id}>
            {s.poster_url && <img src={s.poster_url} alt="" />}
            <div>
              <h3>
                {s.title}{" "}
                <span className={`badge ${s.published ? "published" : "draft"}`}>
                  {s.published ? "Published" : "Draft"}
                </span>
              </h3>
              <p className="muted">
                {s.season_count ?? 0} season(s) · {s.episode_count ?? 0} episode(s)
                {s.category ? ` · ${s.category}` : ""}
                {s.language ? ` · ${s.language}` : ""}
              </p>
              <button disabled={busy || loading} onClick={() => expand(s)}>
                {expanded?.id === s.id ? "Hide manager" : "Manage"}
              </button>
              <button
                disabled={busy || loading}
                onClick={() => {
                  if (!discardForms()) return;
                  closeForms();
                  const draft: SeriesDraft = {
                    title: s.title,
                    slug: s.slug,
                    synopsis: s.synopsis || "",
                    category: s.category || "",
                    language: s.language || "",
                    release_year: s.release_year ? String(s.release_year) : "",
                    poster_url: s.poster_url || "",
                    backdrop_url: s.backdrop_url || "",
                    featured: !!s.featured,
                    published: !!s.published,
                  };
                  setSeriesForm({ editingId: s.id, draft, clean: JSON.stringify(draft) });
                  window.scrollTo({ top: 0, behavior: "smooth" });
                }}
              >
                Edit
              </button>
              <button disabled={busy || loading} onClick={() => toggleSeriesPublish(s)}>
                {s.published ? "Unpublish" : "Publish"}
              </button>
              <button className="secondary" disabled={busy || loading} onClick={() => removeSeries(s)}>
                Delete
              </button>
              {expanded?.id === s.id && (
                <div className="season-manager">
                  {(expanded.seasons || []).map((season) => (
                    <div className="season-block" key={season.id}>
                      <h4>
                        {seasonName(season.season_number, season.title)}{" "}
                        <span className={`badge ${season.published ? "published" : "draft"}`}>
                          {season.published ? "Published" : "Draft"}
                        </span>{" "}
                        <span className="muted">
                          {season.episodes?.length ?? season.episode_count ?? 0} episode(s)
                        </span>
                      </h4>
                      <p>
                        <button
                          className="secondary"
                          disabled={busy}
                          onClick={() => toggleSeasonPublish(s, season)}
                        >
                          {season.published ? "Unpublish season" : "Publish season"}
                        </button>
                        <button
                          className="secondary"
                          disabled={busy}
                          onClick={() => {
                            if (!discardForms()) return;
                            setFieldErrors({});
                            const draft: SeasonDraft = {
                              season_number: String(season.season_number),
                              title: season.title || "",
                              synopsis: season.synopsis || "",
                              poster_url: season.poster_url || "",
                              backdrop_url: season.backdrop_url || "",
                              published: !!season.published,
                            };
                            setSeasonForm({ seriesId: s.id, editingId: season.id, draft, clean: JSON.stringify(draft) });
                            setEpisodeForm(null);
                          }}
                        >
                          Edit season
                        </button>
                        <button
                          disabled={busy}
                          onClick={() => openEpisodeEditor(s, season)}
                        >
                          Add episode
                        </button>
                        <button className="secondary" disabled={busy} onClick={() => removeSeason(s, season)}>
                          Delete season
                        </button>
                      </p>
                      <div className="episode-list">
                        {(season.episodes || []).map((ep) => (
                          <p key={ep.id}>
                            <strong>E{ep.episode_number}</strong> {ep.title || ""}{" "}
                            <span className="muted">
                              {ep.published ? "Published" : "Draft"} · {ep.sources?.length || 0} sources
                            </span>{" "}
                            <button
                              className="secondary"
                              disabled={busy}
                              onClick={() => openEpisodeEditor(s, season, ep)}
                            >
                              Edit
                            </button>
                            <button
                              className="secondary"
                              disabled={busy}
                              onClick={() => toggleEpisodePublish(s, season, ep)}
                            >
                              {ep.published ? "Unpublish" : "Publish"}
                            </button>
                            <button className="secondary" disabled={busy} onClick={() => removeEpisode(s, season, ep)}>
                              Delete
                            </button>
                          </p>
                        ))}
                        {!(season.episodes || []).length && (
                          <p className="muted">No episodes in this season yet.</p>
                        )}
                      </div>
                      {episodeForm && episodeForm.seasonId === season.id && (
                        <form onSubmit={saveEpisode} aria-label={episodeForm.editingId ? "Edit episode" : "Add episode"}>
                          <fieldset disabled={busy}>
                            <legend>
                              {episodeForm.editingId ? "Edit episode" : "Add episode"} ·{" "}
                              {seasonName(season.season_number, season.title)}
                            </legend>
                            <div className="fields">
                              <label>
                                Episode number
                                <input
                                  aria-label="Episode number"
                                  type="number"
                                  min={1}
                                  max={2000}
                                  required
                                  value={episodeForm.draft.episode_number}
                                  onChange={(e) => patchEpisode({ episode_number: e.target.value })}
                                />
                                <FieldError name="episode_number" errors={fieldErrors} />
                              </label>
                              <label>
                                Title
                                <input
                                  aria-label="Episode title"
                                  value={episodeForm.draft.title}
                                  onChange={(e) => patchEpisode({ title: e.target.value })}
                                />
                                <FieldError name="title" errors={fieldErrors} />
                              </label>
                              <label>
                                Duration (seconds)
                                <input
                                  aria-label="Duration (seconds)"
                                  type="number"
                                  min={1}
                                  max={86400}
                                  value={episodeForm.draft.duration_seconds}
                                  onChange={(e) => patchEpisode({ duration_seconds: e.target.value })}
                                />
                                <FieldError name="duration_seconds" errors={fieldErrors} />
                              </label>
                              <label>
                                Still HTTPS URL
                                <input
                                  aria-label="Still HTTPS URL"
                                  type="url"
                                  value={episodeForm.draft.still_url}
                                  onChange={(e) => patchEpisode({ still_url: e.target.value })}
                                />
                                <FieldError name="still_url" errors={fieldErrors} />
                              </label>
                            </div>
                            <label htmlFor="episode-synopsis">Synopsis</label>
                            <textarea
                              id="episode-synopsis"
                              rows={2}
                              value={episodeForm.draft.synopsis}
                              onChange={(e) => patchEpisode({ synopsis: e.target.value })}
                            />
                            <div className="checks">
                              <label>
                                <input
                                  type="checkbox"
                                  checked={episodeForm.draft.published}
                                  onChange={(e) => patchEpisode({ published: e.target.checked })}
                                />{" "}
                                Published
                              </label>
                            </div>
                            <h5>Video sources</h5>
                            <p className="muted">
                              HLS, MP4 and DASH streams plus YouTube links are
                              supported. YouTube sources play as embedded
                              videos and are not signed. Saving replaces the
                              full source list.
                            </p>
                            <FieldError name="sources" errors={fieldErrors} />
                            {episodeForm.draft.sources.map((src, i) => (
                              <fieldset key={i}>
                                <legend>Source {i + 1}</legend>
                                <div className="fields">
                                  <label>
                                    Label
                                    <input
                                      aria-label="Label"
                                      required
                                      value={src.label}
                                      onChange={(e) => patchEpisodeSource(i, { label: e.target.value })}
                                    />
                                    <FieldError name={`sources.${i}.label`} errors={fieldErrors} />
                                  </label>
                                  <label>
                                    Format
                                    <select
                                      aria-label="Format"
                                      value={src.kind}
                                      onChange={(e) =>
                                        patchEpisodeSource(i, { kind: e.target.value as Source["kind"] })
                                      }
                                    >
                                      <option value="hls">HLS</option>
                                      <option value="mp4">MP4</option>
                                      <option value="dash">DASH</option>
                                      <option value="youtube">YouTube link</option>
                                    </select>
                                    <FieldError name={`sources.${i}.kind`} errors={fieldErrors} />
                                  </label>
                                  <label className="wide">
                                    HTTPS stream URL
                                    <input
                                      aria-label="HTTPS stream URL"
                                      type="url"
                                      required
                                      value={src.url || ""}
                                      onChange={(e) => patchEpisodeSource(i, { url: e.target.value })}
                                    />
                                    <FieldError name={`sources.${i}.url`} errors={fieldErrors} />
                                  </label>
                                  <label>
                                    Quality
                                    <input
                                      aria-label="Quality"
                                      value={src.quality || ""}
                                      onChange={(e) => patchEpisodeSource(i, { quality: e.target.value })}
                                    />
                                  </label>
                                  <label>
                                    Language
                                    <input
                                      aria-label="Language"
                                      value={src.language || ""}
                                      onChange={(e) => patchEpisodeSource(i, { language: e.target.value })}
                                    />
                                  </label>
                                </div>
                                <div className="checks">
                                  <label>
                                    <input
                                      type="checkbox"
                                      checked={!!src.enabled}
                                      onChange={(e) => patchEpisodeSource(i, { enabled: e.target.checked })}
                                    />{" "}
                                    Enabled
                                  </label>
                                  <label>
                                    <input
                                      type="radio"
                                      name={`episode-default-source-${episodeForm.editingId ?? "new"}`}
                                      checked={!!src.is_default}
                                      onChange={() => patchEpisodeSource(i, { is_default: true })}
                                    />{" "}
                                    Default
                                  </label>
                                  <button
                                    type="button"
                                    disabled={episodeForm.draft.sources.length === 1}
                                    onClick={() =>
                                      patchEpisode({
                                        sources: episodeForm.draft.sources.filter((_, n) => n !== i),
                                      })
                                    }
                                  >
                                    Remove source
                                  </button>
                                </div>
                              </fieldset>
                            ))}
                            <button
                              type="button"
                              disabled={episodeForm.draft.sources.length >= 10}
                              onClick={() =>
                                patchEpisode({
                                  sources: [
                                    ...episodeForm.draft.sources,
                                    {
                                      ...newEpisodeSource(),
                                      label: `source-${episodeForm.draft.sources.length + 1}`,
                                      is_default: false,
                                    },
                                  ],
                                })
                              }
                            >
                              Add source
                            </button>
                            <div>
                              <button type="submit">{busy ? "Saving…" : "Save episode"}</button>
                              <button
                                type="button"
                                className="secondary"
                                onClick={() => discardForms() && (setEpisodeForm(null), setFieldErrors({}))}
                              >
                                Cancel
                              </button>
                            </div>
                          </fieldset>
                        </form>
                      )}
                    </div>
                  ))}
                  <p>
                    <button
                      disabled={busy}
                      onClick={() => {
                        if (!discardForms()) return;
                        setFieldErrors({});
                        const nextNumber =
                          Math.max(0, ...(expanded.seasons || []).map((x) => x.season_number)) + 1;
                        const draft = blankSeason(nextNumber);
                        setSeasonForm({ seriesId: s.id, editingId: null, draft, clean: JSON.stringify(draft) });
                        setEpisodeForm(null);
                      }}
                    >
                      Add season
                    </button>
                  </p>
                  {seasonForm && seasonForm.seriesId === s.id && (
                    <form onSubmit={saveSeason} aria-label={seasonForm.editingId ? "Edit season" : "Add season"}>
                      <fieldset disabled={busy}>
                        <legend>{seasonForm.editingId ? "Edit season" : "Add season"}</legend>
                        <div className="fields">
                          <label>
                            Season number (0 = specials)
                            <input
                              aria-label="Season number"
                              type="number"
                              min={0}
                              max={999}
                              required
                              value={seasonForm.draft.season_number}
                              onChange={(e) =>
                                setSeasonForm((f) =>
                                  f ? { ...f, draft: { ...f.draft, season_number: e.target.value } } : f,
                                )
                              }
                            />
                            <FieldError name="season_number" errors={fieldErrors} />
                          </label>
                          <label>
                            Season title
                            <input
                              aria-label="Season title"
                              value={seasonForm.draft.title}
                              onChange={(e) =>
                                setSeasonForm((f) => (f ? { ...f, draft: { ...f.draft, title: e.target.value } } : f))
                              }
                            />
                            <FieldError name="title" errors={fieldErrors} />
                          </label>
                          <label>
                            Poster HTTPS URL
                            <input
                              aria-label="Season poster HTTPS URL"
                              type="url"
                              value={seasonForm.draft.poster_url}
                              onChange={(e) =>
                                setSeasonForm((f) =>
                                  f ? { ...f, draft: { ...f.draft, poster_url: e.target.value } } : f,
                                )
                              }
                            />
                            <FieldError name="poster_url" errors={fieldErrors} />
                          </label>
                          <label>
                            Backdrop HTTPS URL
                            <input
                              aria-label="Season backdrop HTTPS URL"
                              type="url"
                              value={seasonForm.draft.backdrop_url}
                              onChange={(e) =>
                                setSeasonForm((f) =>
                                  f ? { ...f, draft: { ...f.draft, backdrop_url: e.target.value } } : f,
                                )
                              }
                            />
                            <FieldError name="backdrop_url" errors={fieldErrors} />
                          </label>
                        </div>
                        <label htmlFor="season-synopsis">Season synopsis</label>
                        <textarea
                          id="season-synopsis"
                          rows={2}
                          value={seasonForm.draft.synopsis}
                          onChange={(e) =>
                            setSeasonForm((f) => (f ? { ...f, draft: { ...f.draft, synopsis: e.target.value } } : f))
                          }
                        />
                        <div className="checks">
                          <label>
                            <input
                              type="checkbox"
                              checked={seasonForm.draft.published}
                              onChange={(e) =>
                                setSeasonForm((f) =>
                                  f ? { ...f, draft: { ...f.draft, published: e.target.checked } } : f,
                                )
                              }
                            />{" "}
                            Published
                          </label>
                        </div>
                        <div>
                          <button type="submit">{busy ? "Saving…" : "Save season"}</button>
                          <button
                            type="button"
                            className="secondary"
                            onClick={() => discardForms() && (setSeasonForm(null), setFieldErrors({}))}
                          >
                            Cancel
                          </button>
                        </div>
                      </fieldset>
                    </form>
                  )}
                </div>
              )}
            </div>
          </article>
        ))}
      </div>
      <nav aria-label="Series pages">
        <button disabled={page <= 1 || loading || busy} onClick={() => setPage((p) => p - 1)}>
          Previous
        </button>
        <span>
          Page {page} of {last}
        </span>
        <button disabled={page >= last || loading || busy} onClick={() => setPage((p) => p + 1)}>
          Next
        </button>
      </nav>
    </section>
  );
}
