"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useState,
} from "react";
import { api, errorText, User } from "../lib/api";
type Session = {
  token: string;
  user: User | null;
  signIn: (token: string, user: User) => void;
  expire: () => void;
};
const Context = createContext<Session>({
  token: "",
  user: null,
  signIn: () => {},
  expire: () => {},
});
export const useSession = () => useContext(Context);
export default function SessionProvider({
  children,
}: {
  children: React.ReactNode;
}) {
  const pathname = usePathname();
  const [menuOpen, setMenuOpen] = useState(false);
  useEffect(() => setMenuOpen(false), [pathname]);
  const links = [
    ["/", "Home"],
    ["/movies", "Movies"],
    ["/series", "Series"],
    ["/genres", "Genres"],
    ["/my-list", "My List"],
  ];
  const [token, setToken] = useState("");
  const [user, setUser] = useState<User | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const expire = useCallback(() => {
    setToken("");
    setUser(null);
  }, []);
  useEffect(() => {
    const onExpiry = (event: Event) => {
      if ((event as CustomEvent<string>).detail === token) {
        expire();
        setError("Your session expired. Please sign in again.");
      }
    };
    window.addEventListener("hyperline:expired", onExpiry);
    return () => window.removeEventListener("hyperline:expired", onExpiry);
  }, [token, expire]);
  async function logout() {
    setBusy(true);
    setError("");
    try {
      await api("/auth/logout", token, { method: "POST" });
      expire();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <Context.Provider
      value={{
        token,
        user,
        expire,
        signIn: (t, u) => {
          setToken(t);
          setUser(u);
          setError("");
        },
      }}
    >
      <a className="skip-link" href="#main-content">
        Skip to content
      </a>
      <header className="site viewer-header">
        <Link href="/" className="identity" aria-label="HyperLine home">
          <img src="/hl-logo.png" alt="" width="42" height="42" />
          <span>
            HYPERLINE<small>BEYOND THE ORDINARY</small>
          </span>
        </Link>
        <button
          className="menu-toggle secondary"
          aria-expanded={menuOpen}
          aria-controls="viewer-navigation"
          onClick={() => setMenuOpen((v) => !v)}
        >
          {menuOpen ? "Close" : "Menu"}
          <span aria-hidden="true">☰</span>
        </button>
        <nav
          id="viewer-navigation"
          className={`viewer-nav${menuOpen ? " is-open" : ""}`}
          aria-label="Main navigation"
        >
          {links.map(([href, label]) => (
            <Link
              key={href}
              href={href}
              aria-current={
                (href === "/" ? pathname === href : pathname.startsWith(href))
                  ? "page"
                  : undefined
              }
            >
              {label}
            </Link>
          ))}
        </nav>
        <div className="nav-right">
          <Link
            className="search-link"
            href="/search"
            aria-label="Search titles"
          >
            <svg
              viewBox="0 0 24 24"
              width="20"
              height="20"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.7"
              aria-hidden="true"
            >
              <circle cx="10.5" cy="10.5" r="6.5" />
              <path d="m16 16 5 5" />
            </svg>
            <span>Search</span>
          </Link>
          {user ? (
            <>
              <span className="account-name" title={user.name}>
                {user.name}
              </span>
              <button
                className="secondary small"
                disabled={busy}
                onClick={logout}
              >
                Sign out
              </button>
            </>
          ) : (
            <Link className="button small" href="/login">
              Sign in
            </Link>
          )}
        </div>
      </header>
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      <div id="main-content" tabIndex={-1}>
        {children}
      </div>
      <footer className="site">
        <div className="footer-top">
          <div>
            <Link href="/" className="identity">
              <img src="/hl-logo.png" alt="" width="36" height="36" />
              <span>HYPERLINE</span>
            </Link>
            <p className="muted">Your next story starts here.</p>
          </div>
          <nav aria-label="Footer navigation">
            <Link href="/movies">Browse movies</Link>
            <Link href="/series">Discover series</Link>
            <Link href="/genres">Browse genres</Link>
            <Link href="/my-list">Saved movies</Link>
          </nav>
        </div>
        <div className="footer-bottom">
          <span>© {new Date().getFullYear()} HyperLine OTT</span>
          <span>Made for the stories you love.</span>
        </div>
      </footer>
    </Context.Provider>
  );
}
