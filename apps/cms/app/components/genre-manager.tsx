"use client";
import { FormEvent, useEffect, useState } from "react";
import { api, errorText, Genre } from "../../lib/api";
const blank = () => ({
  name: "",
  name_my: "",
  slug: "",
  enabled: true,
  sort_order: 0,
});
export default function GenreManager({
  token,
  changed,
}: {
  token: string;
  changed: () => void;
}) {
  const [genres, setGenres] = useState<Genre[]>([]);
  const [draft, setDraft] = useState(blank);
  const [editing, setEditing] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [revision, setRevision] = useState(0);
  const [query, setQuery] = useState("");
  const [message, setMessage] = useState("");
  const [clean, setClean] = useState(JSON.stringify(blank()));
  const dirty = JSON.stringify(draft) !== clean;
  useEffect(() => {
    const c = new AbortController();
    setLoading(true);
    api<{ data: Genre[] }>("/admin/genres", token, { signal: c.signal })
      .then((r) => setGenres(r.data))
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [token, revision]);
  useEffect(() => {
    if (!dirty) return;
    const leave = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", leave);
    return () => window.removeEventListener("beforeunload", leave);
  }, [dirty]);
  function reset() {
    setDraft(blank());
    setClean(JSON.stringify(blank()));
    setEditing(null);
  }
  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await api(editing ? `/admin/genres/${editing}` : "/admin/genres", token, {
        method: editing ? "PATCH" : "POST",
        body: JSON.stringify(draft),
      });
      reset();
      setRevision((r) => r + 1);
      changed();
      setMessage("Genre saved. Content assignments are preserved.");
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  }
  async function remove(g: Genre) {
    if (!window.confirm(`Delete unused genre ${g.name}?`)) return;
    setBusy(true);
    setError("");
    try {
      await api(`/admin/genres/${g.id}`, token, { method: "DELETE" });
      if (editing === g.id) reset();
      setRevision((r) => r + 1);
      changed();
      setMessage("Unused genre deleted.");
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="workspace-card" aria-label="Genre management">
      <h2>Genre management</h2>
      <p className="muted">
        Disabling hides a genre from discovery. Existing movie and series
        assignments stay intact.
      </p>
      {error && (
        <p role="alert">
          {error}{" "}
          <button
            onClick={() => {
              setError("");
              setRevision((r) => r + 1);
            }}
          >
            Retry listing
          </button>
        </p>
      )}
      {message && <p role="status">{message}</p>}
      <form
        onSubmit={save}
        aria-label={editing ? "Edit genre" : "Create genre"}
      >
        <fieldset disabled={busy}>
          <legend>{editing ? "Edit genre" : "Create genre"}</legend>
          <div className="fields">
            <label>
              English name
              <input
                required
                maxLength={120}
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              />
            </label>
            <label>
              Myanmar name
              <input
                lang="my"
                maxLength={120}
                value={draft.name_my}
                onChange={(e) =>
                  setDraft({ ...draft, name_my: e.target.value })
                }
              />
            </label>
            <label>
              Slug
              <input
                required
                pattern="[a-z0-9]+(-[a-z0-9]+)*"
                maxLength={120}
                value={draft.slug}
                onChange={(e) => setDraft({ ...draft, slug: e.target.value })}
              />
              <small>
                Unique lowercase English letters, numbers and hyphens.
              </small>
            </label>
            <label>
              Sort order
              <input
                type="number"
                min={0}
                max={1000000}
                value={draft.sort_order}
                onChange={(e) =>
                  setDraft({ ...draft, sort_order: Number(e.target.value) })
                }
              />
            </label>
          </div>
          <label className="checks">
            <input
              type="checkbox"
              checked={draft.enabled}
              onChange={(e) =>
                setDraft({ ...draft, enabled: e.target.checked })
              }
            />
            Enabled
          </label>
          <div className="form-actions">
            <button type="submit">{busy ? "Saving…" : "Save genre"}</button>
            <button
              type="button"
              className="secondary"
              onClick={() => {
                if (!dirty || window.confirm("Discard unsaved genre changes?"))
                  reset();
              }}
            >
              New / clear
            </button>
          </div>
        </fieldset>
      </form>
      <label>
        Find genres
        <input
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </label>
      {loading ? (
        <p role="status">Loading genres…</p>
      ) : (
        <div className="table-wrap">
          <table>
            <caption>{genres.length} genres · counts include drafts</caption>
            <thead>
              <tr>
                <th scope="col">Genre</th>
                <th scope="col">Status / order</th>
                <th scope="col">Assignments</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {genres
                .filter((g) =>
                  `${g.name} ${g.name_my || ""} ${g.slug}`
                    .toLowerCase()
                    .includes(query.toLowerCase()),
                )
                .map((g) => (
                  <tr key={g.id}>
                    <td>
                      {g.name}
                      <br />
                      <span lang="my">{g.name_my}</span>
                      <br />
                      <small>{g.slug}</small>
                    </td>
                    <td>
                      {g.enabled ? "Enabled" : "Disabled"} · {g.sort_order}
                    </td>
                    <td>
                      {g.movies_count || 0} movies · {g.series_count || 0}{" "}
                      series
                    </td>
                    <td>
                      <button
                        disabled={busy}
                        onClick={() => {
                          if (
                            dirty &&
                            !window.confirm("Discard unsaved genre changes?")
                          )
                            return;
                          const next = {
                            name: g.name,
                            name_my: g.name_my || "",
                            slug: g.slug,
                            enabled: g.enabled !== false,
                            sort_order: g.sort_order || 0,
                          };
                          setDraft(next);
                          setClean(JSON.stringify(next));
                          setEditing(g.id);
                        }}
                      >
                        Edit {g.name}
                      </button>
                      <button
                        className="secondary"
                        disabled={busy || !!g.movies_count || !!g.series_count}
                        onClick={() => remove(g)}
                      >
                        Delete {g.name}
                      </button>
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
          {!genres.length && (
            <p>No genres yet. Create your first genre above.</p>
          )}
        </div>
      )}
    </section>
  );
}
