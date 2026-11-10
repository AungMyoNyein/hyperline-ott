import { FormEvent, useEffect, useState } from "react";
import { api, errorText, Page } from "../../lib/api";
type Audit = {
  id: number;
  action: string;
  description: string;
  actor: {
    name: string;
    email: string;
  } | null;
  created_at: string;
  properties: unknown;
};
export default function AuditScreen({ token }: { token: string }) {
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [rows, setRows] = useState<Audit[]>([]);
  const [action, setAction] = useState("");
  const [filter, setFilter] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (!open) return;
    const c = new AbortController();
    setLoading(true);
    setError("");
    api<Page<Audit>>(
      `/admin/audit-logs?page=${page}${filter ? "&action=" + encodeURIComponent(filter) : ""}`,
      token,
      { signal: c.signal },
    )
      .then((j) => {
        setRows(j.data);
        setLast(j.meta.last_page);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(errorText(e));
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [open, page, filter, token]);
  function search(e: FormEvent) {
    e.preventDefault();
    setPage(1);
    setFilter(action);
  }
  return (
    <section>
      <h2>Audit log</h2>
      <button aria-expanded={open} onClick={() => setOpen((v) => !v)}>
        {open ? "Hide audit log" : "View audit log"}
      </button>
      {open && (
        <>
          <form onSubmit={search}>
            <label>
              Action
              <input
                value={action}
                maxLength={64}
                placeholder="movie.updated"
                onChange={(e) => setAction(e.target.value)}
              />
            </label>
            <button>Filter audit log</button>
          </form>
          {error && (
            <p className="error" role="alert">
              {error}
            </p>
          )}
          {loading ? (
            <p role="status">Loading audit log…</p>
          ) : rows.length ? (
            rows.map((a) => (
              <article key={a.id}>
                <h3>{a.action}</h3>
                <p>{a.description}</p>
                <p>
                  {a.actor?.name || "Deleted user"} ·{" "}
                  <time dateTime={a.created_at}>
                    {new Date(a.created_at).toLocaleString(undefined, {
                      timeZoneName: "short",
                    })}
                  </time>
                </p>
                <details>
                  <summary>Changes</summary>
                  <pre>{JSON.stringify(a.properties, null, 2)}</pre>
                </details>
              </article>
            ))
          ) : (
            <p>No audit entries.</p>
          )}
          <nav aria-label="Audit pages">
            <button
              disabled={page <= 1 || loading}
              onClick={() => setPage((p) => p - 1)}
            >
              Previous
            </button>
            <span>
              Page {page} of {last}
            </span>
            <button
              disabled={page >= last || loading}
              onClick={() => setPage((p) => p + 1)}
            >
              Next
            </button>
          </nav>
        </>
      )}
    </section>
  );
}
