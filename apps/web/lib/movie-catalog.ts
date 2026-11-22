// Carry only public catalog filters into detail links and return navigation.
export function movieCatalogParams(input: URLSearchParams) {
  const clean = new URLSearchParams();
  const query = (input.get("q") || "").trim();
  const length = Array.from(query).length;
  if (length >= 2 && length <= 120) clean.set("q", query);
  const genre = input.get("genre") || "";
  if (genre.length <= 120 && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(genre))
    clean.set("genre", genre);
  if (input.get("featured") === "1") clean.set("featured", "1");
  const page = input.get("page") || "";
  if (/^[1-9]\d{0,5}$/.test(page)) clean.set("page", page);
  return clean;
}

export function movieCatalogHref(input: URLSearchParams) {
  const query = movieCatalogParams(input).toString();
  return `/movies${query ? `?${query}` : ""}`;
}

export function movieCatalogReturn(value: unknown) {
  if (typeof value !== "string" || !/^\/movies(?:\?|$)/.test(value))
    return "/movies";
  return movieCatalogHref(
    new URLSearchParams(value.split("?").slice(1).join("?")),
  );
}
