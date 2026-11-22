import "./style.css";
import localFont from "next/font/local";
const myanmar = localFont({
  src: [
    {
      path: "./fonts/noto-sans-myanmar-myanmar-400-normal.woff2",
      weight: "400",
      style: "normal",
    },
    {
      path: "./fonts/noto-sans-myanmar-myanmar-700-normal.woff2",
      weight: "700",
      style: "normal",
    },
  ],
  variable: "--font-myanmar",
  display: "swap",
  preload: false,
  adjustFontFallback: false,
  fallback: ["Arial", "Helvetica", "Myanmar Text", "Padauk", "sans-serif"],
});
export const metadata = { title: "HyperLine OTT" };
export default function Layout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body className={myanmar.variable}>{children}</body>
    </html>
  );
}
