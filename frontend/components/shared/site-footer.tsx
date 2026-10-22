import { LegalLinks } from "@/components/legal/legal-links";
import { OPERATOR, OPERATOR_DISPLAY_NAME } from "@/lib/legal/operator";
import { RELEASE_DESIGNATION } from "@/lib/release";
import { cn } from "@/lib/utils";

/** PCDCD's public page (FB-BRAND-01): opened in a new tab, without opener or referrer. */
export const DEVELOPER_URL = OPERATOR.publicPageUrl;

/**
 * The release, copyright and operator footer (FB-BRAND-01, wording FB-LEGAL-01
 * D1), shown on the PUBLIC pages only: the Family authentication pages
 * (login, activation, password reset), the Staff login, the public
 * verification page and the legal pages — never inside the authenticated
 * portals. Famboook is the platform and brand; PCDCD operates it and manages
 * the data (no ownership claim either way). The legal links show published
 * pages only. Minimal: the existing small-text tokens, no new colour, RTL
 * like the page. The year is fixed — a computed one could differ between
 * server and browser at New Year.
 */
export function SiteFooter({ className }: { className?: string }) {
  return (
    <footer className={cn("flex flex-col items-center gap-0.5 text-center text-xs leading-relaxed text-subtle-foreground", className)} data-site-footer>
      <p className="font-medium text-muted-foreground" data-release>
        <bdi dir="ltr">{RELEASE_DESIGNATION}</bdi>
      </p>
      <p>© 2026 Famboook — جميع الحقوق محفوظة</p>
      <p data-operator>
        {OPERATOR.roleAr}:{" "}
        <a
          href={DEVELOPER_URL}
          target="_blank"
          rel="noopener noreferrer"
          className="font-medium text-muted-foreground underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-ring"
        >
          {OPERATOR_DISPLAY_NAME}
        </a>
      </p>
      <LegalLinks className="mt-1.5" />
    </footer>
  );
}
