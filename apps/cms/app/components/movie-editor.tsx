import { FormEvent } from "react";
import { Genre, Source } from "../../lib/api";
import { Draft, newSource } from "./movie-draft";
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
type Props = {
  draft: Draft;
  editing: number | null;
  busy: boolean;
  genres: Genre[];
  errors: Record<string, string[]>;
  save: (e: FormEvent) => void;
  field: <K extends keyof Draft>(key: K, value: Draft[K]) => void;
  source: (index: number, patch: Partial<Source>) => void;
  clear: () => void;
};
export default function MovieEditor({
  draft,
  editing,
  busy,
  genres,
  errors,
  save,
  field,
  source,
  clear,
}: Props) {
  return (
    <>
      <h2>{editing ? "Edit movie" : "Create movie"}</h2>
      <form onSubmit={save}>
        <fieldset disabled={busy}>
          <div className="fields">
            {(
              [
                "title",
                "slug",
                "release_year",
                "duration_minutes",
                "poster_url",
                "backdrop_url",
              ] as const
            ).map((k) => (
              <label key={k}>
                {
                  {
                    title: "Title",
                    slug: "Slug (optional on create)",
                    release_year: "Release year",
                    duration_minutes: "Duration (minutes)",
                    poster_url: "Poster HTTPS URL",
                    backdrop_url: "Backdrop HTTPS URL",
                  }[k]
                }
                <input
                  aria-invalid={!!errors[k]}
                  aria-describedby={errors[k] ? `error-${k}` : undefined}
                  aria-label={
                    {
                      title: "Title",
                      slug: "Slug (optional on create)",
                      release_year: "Release year",
                      duration_minutes: "Duration (minutes)",
                      poster_url: "Poster HTTPS URL",
                      backdrop_url: "Backdrop HTTPS URL",
                    }[k]
                  }
                  value={draft[k]}
                  required={k === "title"}
                  type={
                    k.endsWith("_url")
                      ? "url"
                      : k === "release_year" || k === "duration_minutes"
                        ? "number"
                        : "text"
                  }
                  onChange={(e) => field(k, e.target.value)}
                />
                <FieldError name={k} errors={errors} />
              </label>
            ))}
          </div>
          <label htmlFor="movie-synopsis">Synopsis</label>
          <textarea
            id="movie-synopsis"
            rows={4}
            value={draft.synopsis}
            onChange={(e) => field("synopsis", e.target.value)}
          />
          <FieldError name="synopsis" errors={errors} />
          <div className="checks">
            <label>
              <input
                type="checkbox"
                checked={draft.published}
                onChange={(e) => field("published", e.target.checked)}
              />{" "}
              Published
            </label>
            <label>
              <input
                type="checkbox"
                checked={draft.featured}
                onChange={(e) => field("featured", e.target.checked)}
              />{" "}
              Featured
            </label>
          </div>
          <fieldset>
            <legend>Genres</legend>
            <FieldError name="genres" errors={errors} />
            {genres.length ? (
              genres.map((g) => (
                <label className="check" key={g.id}>
                  <input
                    type="checkbox"
                    checked={draft.genres.includes(g.slug)}
                    onChange={(e) =>
                      field(
                        "genres",
                        e.target.checked
                          ? [...draft.genres, g.slug]
                          : draft.genres.filter((s) => s !== g.slug),
                      )
                    }
                  />
                  {g.name}
                </label>
              ))
            ) : (
              <p className="muted">No genres available.</p>
            )}
            <p className="muted">
              Assign existing genres here. Genre creation and deletion are not
              supported by this API.
            </p>
          </fieldset>
          <h3>Video sources</h3>
          <p className="muted">
            HLS, MP4 and DASH streams plus YouTube links are supported. YouTube
            sources play as embedded videos and are not signed.
          </p>
          <FieldError name="sources" errors={errors} />
          {draft.sources.map((s, i) => (
            <fieldset key={i}>
              <legend>Source {i + 1}</legend>
              <div className="fields">
                <label>
                  Label
                  <input
                    aria-label="Label"
                    aria-invalid={!!errors[`sources.${i}.label`]}
                    aria-describedby={
                      errors[`sources.${i}.label`]
                        ? `error-sources.${i}.label`
                        : undefined
                    }
                    required
                    value={s.label}
                    onChange={(e) => source(i, { label: e.target.value })}
                  />
                  <FieldError name={`sources.${i}.label`} errors={errors} />
                </label>
                <label>
                  Format
                  <select
                    aria-label="Format"
                    value={s.kind}
                    onChange={(e) =>
                      source(i, { kind: e.target.value as Source["kind"] })
                    }
                  >
                    <option value="hls">HLS</option>
                    <option value="mp4">MP4</option>
                    <option value="dash">DASH</option>
                    <option value="youtube">YouTube link</option>
                  </select>
                  <FieldError name={`sources.${i}.kind`} errors={errors} />
                </label>
                <label className="wide">
                  HTTPS stream URL
                  <input
                    aria-label="HTTPS stream URL"
                    aria-invalid={!!errors[`sources.${i}.url`]}
                    aria-describedby={
                      errors[`sources.${i}.url`]
                        ? `error-sources.${i}.url`
                        : undefined
                    }
                    type="url"
                    required
                    value={s.url || ""}
                    onChange={(e) => source(i, { url: e.target.value })}
                  />
                  <FieldError name={`sources.${i}.url`} errors={errors} />
                </label>
                <label>
                  Quality
                  <input
                    aria-label="Quality"
                    value={s.quality || ""}
                    onChange={(e) => source(i, { quality: e.target.value })}
                  />
                  <FieldError name={`sources.${i}.quality`} errors={errors} />
                </label>
                <label>
                  Language
                  <input
                    aria-label="Language"
                    value={s.language || ""}
                    onChange={(e) => source(i, { language: e.target.value })}
                  />
                  <FieldError name={`sources.${i}.language`} errors={errors} />
                </label>
              </div>
              <div className="checks">
                <label>
                  <input
                    type="checkbox"
                    checked={!!s.enabled}
                    onChange={(e) => source(i, { enabled: e.target.checked })}
                  />{" "}
                  Enabled
                </label>
                <label>
                  <input
                    type="radio"
                    name="default-source"
                    checked={!!s.is_default}
                    onChange={() => source(i, { is_default: true })}
                  />{" "}
                  Default
                </label>
                <button
                  type="button"
                  disabled={draft.sources.length === 1}
                  onClick={() =>
                    field(
                      "sources",
                      draft.sources.filter((_, n) => n !== i),
                    )
                  }
                >
                  Remove source
                </button>
              </div>
            </fieldset>
          ))}
          <button
            type="button"
            disabled={draft.sources.length >= 10}
            onClick={() =>
              field("sources", [
                ...draft.sources,
                {
                  ...newSource(),
                  label: `source-${draft.sources.length + 1}`,
                  is_default: false,
                },
              ])
            }
          >
            Add source
          </button>
          <div>
            <button type="submit">{busy ? "Saving…" : "Save movie"}</button>
            <button type="button" className="secondary" onClick={clear}>
              Clear editor
            </button>
          </div>
        </fieldset>
      </form>
    </>
  );
}
