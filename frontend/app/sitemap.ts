import type { MetadataRoute } from "next";

// FB-SEO-04: the homepage is the only page approved for indexing. Add a URL here
// only together with removing its noindex.
export default function sitemap(): MetadataRoute.Sitemap {
  return [{ url: "https://famboook.com/" }];
}
