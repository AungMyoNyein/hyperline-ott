"use client";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
export default function Rail({
  title,
  href,
  children,
  empty = false,
  label,
}: {
  title: string;
  href?: string;
  children: React.ReactNode;
  empty?: boolean;
  label?: string;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const [edges, setEdges] = useState({ back: false, next: false });
  useEffect(() => {
    const element = ref.current;
    if (!element) return;
    const update = () =>
      setEdges({
        back: element.scrollLeft > 1,
        next:
          element.scrollLeft + element.clientWidth < element.scrollWidth - 1,
      });
    const observer = new ResizeObserver(update);
    observer.observe(element);
    element.addEventListener("scroll", update, { passive: true });
    update();
    return () => {
      observer.disconnect();
      element.removeEventListener("scroll", update);
    };
  }, [children]);
  function move(direction: number) {
    ref.current?.scrollBy({
      left: direction * ref.current.clientWidth * 0.8,
      behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches
        ? "instant"
        : "smooth",
    });
  }
  return (
    <section className="rail" aria-label={label || title}>
      <div className="rail-head">
        <h2>{title}</h2>
        <div className="rail-controls">
          {href && (
            <Link href={href}>
              View all <span aria-hidden="true">↗</span>
            </Link>
          )}
          {!empty && (
            <div className="rail-arrows">
              <button
                className="icon secondary"
                aria-label={`Scroll ${title} backward`}
                disabled={!edges.back}
                onClick={() => move(-1)}
              >
                ←
              </button>
              <button
                className="icon secondary"
                aria-label={`Scroll ${title} forward`}
                disabled={!edges.next}
                onClick={() => move(1)}
              >
                →
              </button>
            </div>
          )}
        </div>
      </div>
      <div ref={ref} className={empty ? "rail-empty" : "rail-scroll"}>
        {children}
      </div>
    </section>
  );
}
