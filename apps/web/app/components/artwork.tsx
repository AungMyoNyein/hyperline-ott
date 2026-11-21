"use client";
import Image from "next/image";
import { useState } from "react";

/** External artwork stays in the browser; never proxy arbitrary image hosts. */
export default function Artwork({
  src,
  title,
  shape = "poster",
  priority = false,
}: {
  src?: string | null;
  title: string;
  shape?: "poster" | "landscape" | "vertical";
  priority?: boolean;
}) {
  const [failed, setFailed] = useState("");
  const safe = !!src && /^(https?:\/\/|\/(?!\/))/.test(src);
  return (
    <div
      className={`artwork artwork-${shape}`}
      data-youtube-thumbnail={
        !!src && /^https:\/\/i\.ytimg\.com\//.test(src) ? "true" : undefined
      }
    >
      {safe && failed !== src ? (
        <Image
          key={src}
          src={src!}
          alt=""
          fill
          unoptimized
          sizes={
            shape === "landscape"
              ? "100vw"
              : "(max-width: 600px) 45vw, (max-width: 1000px) 25vw, 220px"
          }
          priority={priority}
          onError={() => setFailed(src!)}
        />
      ) : (
        <div className="artwork-fallback" aria-hidden="true">
          <span className="brand-monogram">
            H<span>│</span>L
          </span>
          <span>{title}</span>
        </div>
      )}
    </div>
  );
}
