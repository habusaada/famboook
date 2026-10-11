import { cn } from "@/lib/utils";

/** The developer's page (FB-BRAND-01): opened in a new tab, without opener or referrer. */
export const DEVELOPER_URL = "https://www.facebook.com/pcdcd.gaza";

/**
 * The copyright and development credit (FB-BRAND-01), shared by the Family
 * authentication pages, the Family Portal, the Staff login, the Staff
 * application and the public verification page. Copyright belongs to
 * Famboook; PCDCD is credited as the developer. Minimal: the existing
 * small-text tokens, no new colour, RTL like the page. The year is fixed —
 * a computed one could differ between server and browser at New Year.
 */
export function SiteFooter({ className }: { className?: string }) {
  return (
    <footer className={cn("flex flex-col items-center gap-0.5 text-center text-xs leading-relaxed text-subtle-foreground", className)} data-site-footer>
      <p>© 2026 فامبوك. جميع الحقوق محفوظة.</p>
      <p>
        تطوير{" "}
        <a
          href={DEVELOPER_URL}
          target="_blank"
          rel="noopener noreferrer"
          className="font-medium text-muted-foreground underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-ring"
        >
          المركز الفلسطيني للبيانات والتنمية المجتمعية – PCDCD
        </a>
      </p>
    </footer>
  );
}
