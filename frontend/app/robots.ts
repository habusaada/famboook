import type { MetadataRoute } from "next";

// FB-SEO-03 (docs/adr/FB-WEB-04-PUBLIC-HOMEPAGE.md §7): crawling policy, not
// access control — authentication and noindex keep private pages private.
// Private areas are blocked. The sign-in and activation pages the public pages
// link to, /verify and the legal pages stay crawlable, so crawlers read their
// noindex instead of indexing a blocked URL without its content. Google applies
// the longest matching rule, so each Allow below wins over "/family".
export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: ["/", "/family/login", "/family/activate", "/family/forgot-password"],
      disallow: [
        // Staff Portal (app/(staff); /login stays crawlable).
        "/dashboard",
        "/administration",
        "/announcements",
        "/assessments",
        "/assistances",
        "/change-requests",
        "/civil-registry",
        "/families",
        "/followups",
        "/needs",
        "/people",
        "/reports",
        "/dev-login",
        // Family Portal, except the entry pages allowed above.
        "/family",
        "/api/",
      ],
    },
    sitemap: "https://famboook.com/sitemap.xml",
  };
}
