import { Genre, Movie } from "../../lib/api";
export type Filters = {
  q: string;
  published: string;
  featured: string;
  genre: string;
  per_page: string;
};
type Props = {
  movies: Movie[];
  genres: Genre[];
  filters: Filters;
  filter: (key: keyof Filters, value: string) => void;
  apply: () => void;
  loading: boolean;
  busy: boolean;
  page: number;
  last: number;
  setPage: (page: number) => void;
  edit: (movie: Movie) => void;
  mutate: (movie: Movie, remove?: boolean) => void;
};
export default function MovieLibrary({
  movies,
  genres,
  filters,
  filter,
  apply,
  loading,
  busy,
  page,
  last,
  setPage,
  edit,
  mutate,
}: Props) {
  return (
    <section>
      <h2>Movie library</h2>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          apply();
        }}
      >
        <div className="fields">
          <label>
            Search movies
            <input
              value={filters.q}
              minLength={2}
              maxLength={120}
              onChange={(e) => filter("q", e.target.value)}
            />
          </label>
          <label>
            Publication
            <select
              aria-label="Publication"
              value={filters.published}
              onChange={(e) => filter("published", e.target.value)}
            >
              <option value="">All</option>
              <option value="1">Published</option>
              <option value="0">Draft</option>
            </select>
          </label>
          <label>
            Featured
            <select
              aria-label="Featured"
              value={filters.featured}
              onChange={(e) => filter("featured", e.target.value)}
            >
              <option value="">All</option>
              <option value="1">Featured</option>
              <option value="0">Not featured</option>
            </select>
          </label>
          <label>
            Genre
            <select
              aria-label="Genre"
              value={filters.genre}
              onChange={(e) => filter("genre", e.target.value)}
            >
              <option value="">All genres</option>
              {genres.map((g) => (
                <option key={g.id} value={g.slug}>
                  {g.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Movies per page
            <select
              aria-label="Movies per page"
              value={filters.per_page}
              onChange={(e) => filter("per_page", e.target.value)}
            >
              {[10, 25, 50, 100].map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        </div>
        <button>Apply filters</button>
      </form>
      {loading && <p role="status">Loading library…</p>}
      {!loading && !movies.length && <p>No movies on this page.</p>}
      <div className="library">
        {movies.map((m) => (
          <article key={m.id}>
            {m.poster_url && <img src={m.poster_url} alt="" />}
            <div>
              <h3>{m.title}</h3>
              <p className="muted">
                {m.published ? "Published" : "Draft"} · {m.sources?.length || 0}{" "}
                sources
              </p>
              <button disabled={busy || loading} onClick={() => edit(m)}>
                Edit
              </button>
              <button disabled={busy || loading} onClick={() => mutate(m)}>
                {m.published ? "Unpublish" : "Publish"}
              </button>
              <button
                className="secondary"
                disabled={busy || loading}
                onClick={() => mutate(m, true)}
              >
                Delete
              </button>
            </div>
          </article>
        ))}
      </div>
      <nav aria-label="Library pages">
        <button
          disabled={page <= 1 || loading || busy}
          onClick={() => setPage(page - 1)}
        >
          Previous
        </button>
        <span>
          Page {page} of {last}
        </span>
        <button
          disabled={page >= last || loading || busy}
          onClick={() => setPage(page + 1)}
        >
          Next
        </button>
      </nav>
    </section>
  );
}
