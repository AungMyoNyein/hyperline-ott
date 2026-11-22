import Detail from "./detail";
import { movieCatalogReturn } from "../../../lib/movie-catalog";
export default async function MoviePage({
  params,
  searchParams,
}: {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ from?: string | string[] }>;
}) {
  const { from } = await searchParams;
  const backHref = movieCatalogReturn(from);
  return <Detail slug={(await params).slug} backHref={backHref} />;
}
