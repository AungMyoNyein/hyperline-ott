"use client";
import { FormEvent, useEffect, useState } from "react";
import {
  api,
  ApiError,
  errorText,
  Genre,
  Movie,
  Page,
  Source,
} from "../lib/api";
import { blank, Draft } from "./components/movie-draft";
import MovieEditor from "./components/movie-editor";
import MovieLibrary from "./components/movie-library";
import SeriesLibrary from "./components/series-library";
import AuditScreen from "./components/audit-screen";
type Dashboard = {
  movies: {
    total: number;
    published: number;
    draft: number;
    featured: number;
  };
  genres: number;
  viewers: {
    playback_sessions_24h: number;
  };
  recent_activity: {
    id: number;
    action: string;
    description: string;
    created_at: string;
  }[];
};
export default function CMS() {
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [filters, setFilters] = useState({
    q: "",
    published: "",
    featured: "",
    genre: "",
    per_page: "50",
  });
  const [query, setQuery] = useState("");
  const [clean, setClean] = useState(() => JSON.stringify(blank()));
  const [token, setToken] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [movies, setMovies] = useState<Movie[]>([]);
  const [genres, setGenres] = useState<Genre[]>([]);
  const [dashboard, setDashboard] = useState<Dashboard | null>(null);
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [draft, setDraft] = useState<Draft>(blank);
  const [editing, setEditing] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [revision, setRevision] = useState(0);
  const dirty = JSON.stringify(draft) !== clean;
  function discard() {
    return !dirty || window.confirm("Discard unsaved movie changes?");
  }
  function clearEditor() {
    if (!discard()) return;
    setDraft(blank());
    setClean(JSON.stringify(blank()));
    setEditing(null);
    setFieldErrors({});
  }
  useEffect(() => {
    if (!dirty) return;
    const leave = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", leave);
    return () => window.removeEventListener("beforeunload", leave);
  }, [dirty]);
  useEffect(() => {
    const expired = (e: Event) => {
      if ((e as CustomEvent<string>).detail === token) {
        setToken("");
        setMovies([]);
        setDashboard(null);
        setError("Your session expired. Please sign in again.");
      }
    };
    window.addEventListener("hyperline:expired", expired);
    return () => window.removeEventListener("hyperline:expired", expired);
  }, [token]);
  function fail(e: unknown) {
    if (e instanceof ApiError) setFieldErrors(e.errors);
    setError(errorText(e));
  }
  useEffect(() => {
    if (!token) return;
    const controller = new AbortController();
    setLoading(true);
    setError("");
    Promise.all([
      api<Page<Movie>>(`/admin/movies?page=${page}&${query}`, token, {
        signal: controller.signal,
      }),
      api<{
        data: Genre[];
      }>("/genres", token, { signal: controller.signal }),
      api<{
        data: Dashboard;
      }>("/admin/dashboard", token, { signal: controller.signal }),
    ])
      .then(([list, g, d]) => {
        setMovies(list.data);
        setLast(list.meta.last_page);
        setGenres(g.data);
        setDashboard(d.data);
      })
      .catch((e) => {
        if (!controller.signal.aborted) fail(e);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [token, page, revision, query]);
  async function login(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const j = await api<{
        data: {
          token: string;
        };
      }>("/admin/login", "", {
        method: "POST",
        body: JSON.stringify({ email, password, device_name: "HyperLine CMS" }),
      });
      setToken(j.data.token);
      setPassword("");
      setMessage("");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function logout() {
    if (!discard()) return;
    setBusy(true);
    setError("");
    try {
      await api("/admin/logout", token, { method: "POST" });
      setToken("");
      setMovies([]);
      setDashboard(null);
      setEditing(null);
      setDraft(blank());
      setClean(JSON.stringify(blank()));
      setFieldErrors({});
      setMessage("Signed out.");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  function field<K extends keyof Draft>(key: K, value: Draft[K]) {
    setDraft((d) => ({ ...d, [key]: value }));
  }
  function source(index: number, patch: Partial<Source>) {
    setDraft((d) => ({
      ...d,
      sources: d.sources.map((s, i) =>
        i === index
          ? { ...s, ...patch }
          : patch.is_default
            ? { ...s, is_default: false }
            : s,
      ),
    }));
  }
  function edit(m: Movie) {
    if (!discard()) return;
    setFieldErrors({});
    setEditing(m.id);
    setDraft({
      title: m.title,
      slug: m.slug,
      synopsis: m.synopsis || "",
      release_year: String(m.release_year || ""),
      duration_minutes: String(m.duration_minutes || ""),
      poster_url: m.poster_url || "",
      backdrop_url: m.backdrop_url || "",
      published: !!m.published,
      featured: m.featured,
      genres: m.genres.map((g) => g.slug),
      sources: (m.sources || []).map((s) => ({ ...s })),
    });
    setClean(
      JSON.stringify({
        title: m.title,
        slug: m.slug,
        synopsis: m.synopsis || "",
        release_year: String(m.release_year || ""),
        duration_minutes: String(m.duration_minutes || ""),
        poster_url: m.poster_url || "",
        backdrop_url: m.backdrop_url || "",
        published: !!m.published,
        featured: m.featured,
        genres: m.genres.map((g) => g.slug),
        sources: (m.sources || []).map((s) => ({ ...s })),
      }),
    );
    setError("");
    setMessage("");
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
  async function save(e: FormEvent) {
    e.preventDefault();
    setFieldErrors({});
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const body = {
        ...draft,
        slug: draft.slug || undefined,
        synopsis: draft.synopsis || null,
        release_year: draft.release_year ? Number(draft.release_year) : null,
        duration_minutes: draft.duration_minutes
          ? Number(draft.duration_minutes)
          : null,
        poster_url: draft.poster_url || null,
        backdrop_url: draft.backdrop_url || null,
        sources: draft.sources.map((s) => ({
          ...s,
          quality: s.quality || null,
          language: s.language || null,
        })),
      };
      await api(editing ? `/admin/movies/${editing}` : "/admin/movies", token, {
        method: editing ? "PATCH" : "POST",
        body: JSON.stringify(body),
      });
      setDraft(blank());
      setClean(JSON.stringify(blank()));
      setEditing(null);
      setRevision((r) => r + 1);
      setMessage("Movie saved.");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  async function mutate(m: Movie, remove = false) {
    if (editing === m.id && !discard()) return;
    if (
      remove &&
      !window.confirm(`Delete “${m.title}”? This cannot be undone.`)
    )
      return;
    setBusy(true);
    setError("");
    try {
      await api(`/admin/movies/${m.id}`, token, {
        method: remove ? "DELETE" : "PATCH",
        ...(!remove
          ? { body: JSON.stringify({ published: !m.published }) }
          : {}),
      });
      if (editing === m.id) {
        setEditing(null);
        setDraft(blank());
        setClean(JSON.stringify(blank()));
      }
      if (remove && movies.length === 1 && page > 1) setPage(page - 1);
      else setRevision((r) => r + 1);
      setMessage(remove ? "Movie deleted." : "Publication updated.");
    } catch (e) {
      fail(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <main>
      <header>
        <img src="/hl-logo.png" width="54" height="54" alt="HyperLine" />
        <div>
          <p className="eyebrow">HYPERLINE</p>
          <h1>Content studio</h1>
        </div>
        {token && (
          <button disabled={busy} onClick={logout}>
            Sign out
          </button>
        )}
      </header>
      {error && (
        <p className="error" role="alert">
          {error}
        </p>
      )}
      {message && <p role="status">{message}</p>}
      {!token ? (
        <form onSubmit={login}>
          <h2>Administrator sign in</h2>
          <label>
            Email
            <input
              type="email"
              autoComplete="username"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </label>
          <label>
            Password
            <input
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </label>
          <button disabled={busy}>{busy ? "Signing in…" : "Sign in"}</button>
          <p className="muted">
            Your session has a server-enforced expiry and stays only in this
            tab.
          </p>
        </form>
      ) : (
        <>
          {dashboard && (
            <section aria-label="Dashboard" className="stats">
              {Object.entries(dashboard.movies).map(([label, count]) => (
                <article key={label}>
                  <strong>{count}</strong>
                  <span>{label} movies</span>
                </article>
              ))}
              <article>
                <strong>{dashboard.viewers.playback_sessions_24h}</strong>
                <span>plays in 24 hours</span>
              </article>
            </section>
          )}
          <MovieEditor
            {...{ draft, editing, busy, genres, save, field, source }}
            errors={fieldErrors}
            clear={clearEditor}
          />
          <MovieLibrary
            {...{
              movies,
              genres,
              loading,
              busy,
              page,
              last,
              setPage,
              edit,
              mutate,
              filters,
            }}
            filter={(key, value) => setFilters((f) => ({ ...f, [key]: value }))}
            apply={() => {
              setPage(1);
              setQuery(
                new URLSearchParams(
                  Object.entries(filters).filter(([, v]) => v),
                ).toString(),
              );
            }}
          />
          <SeriesLibrary token={token} />
          <AuditScreen token={token} />
          {dashboard && (
            <section>
              <h2>Recent activity</h2>
              {dashboard.recent_activity.map((a) => (
                <p key={a.id}>
                  {a.description || a.action}{" "}
                  <time className="muted">
                    {new Date(a.created_at).toLocaleString(undefined, {
                      timeZoneName: "short",
                    })}
                  </time>
                </p>
              ))}
            </section>
          )}
        </>
      )}
    </main>
  );
}
