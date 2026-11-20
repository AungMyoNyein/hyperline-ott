import Link from "next/link";
export function Skeleton({
  label = "Loading titles…",
  hero = false,
}: {
  label?: string;
  hero?: boolean;
}) {
  return (
    <div role="status" aria-live="polite" className="loading-state">
      <span className="sr-only">{label}</span>
      {hero && <div className="skeleton skeleton-hero" />}
      <div className="grid skeleton-grid" aria-hidden="true">
        {Array.from({ length: 6 }, (_, i) => (
          <div key={i}>
            <div className="skeleton skeleton-poster" />
            <div className="skeleton skeleton-line" />
          </div>
        ))}
      </div>
    </div>
  );
}
export function EmptyState({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="empty-state">
      <span className="empty-symbol" aria-hidden="true">
        H<span>╱</span>L
      </span>
      <h2>{title}</h2>
      <div className="muted">{children}</div>
    </div>
  );
}
export function ErrorState({
  message,
  retry,
}: {
  message: string;
  retry?: () => void;
}) {
  return (
    <div className="error-state" role="alert">
      <p className="eyebrow">LET’S TRY THAT AGAIN</p>
      <h2>We couldn’t load this page.</h2>
      <p className="muted">{message}</p>
      {retry ? (
        <button onClick={retry}>Try again</button>
      ) : (
        <Link href="/" className="button secondary">
          Back to home
        </Link>
      )}
    </div>
  );
}
export function Progress({
  position,
  duration,
  label = "Watch progress",
}: {
  position?: number | null;
  duration?: number | null;
  label?: string;
}) {
  if (!position || !duration || duration <= 0) return null;
  const value = Math.min(
    100,
    Math.max(0, Math.round((position / duration) * 100)),
  );
  return (
    <div
      className="progress-track"
      role="progressbar"
      aria-valuenow={value}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label}
    >
      <i style={{ width: `${value}%` }} />
    </div>
  );
}
