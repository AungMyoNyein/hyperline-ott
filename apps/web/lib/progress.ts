export type Progress = {
  position_seconds: number;
  duration_seconds: number | null;
  completed: boolean;
};
// Video events can fire many times per second. This gate also deduplicates pause,
// pagehide and cleanup, and allows retry after a failed request.
export function progressGate(interval = 15000) {
  let lastAt = 0;
  let lastKey = "";
  return {
    take(value: Progress, now: number, force = false) {
      const key = JSON.stringify(value);
      if (key === lastKey || (!force && now - lastAt < interval)) return false;
      lastAt = now;
      lastKey = key;
      return true;
    },
    failed() {
      lastKey = "";
    },
  };
}
