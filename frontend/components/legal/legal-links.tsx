import Link from "next/link";
import { publishedLegalPages } from "@/lib/legal/pages";
import { cn } from "@/lib/utils";

/**
 * Links to the PUBLISHED legal and information pages only (FB-LEGAL-01B) —
 * a page awaiting approval is never linked, so no pending document is ever
 * presented as a policy. Renders nothing when none is published. No client
 * state: usable in server and client trees alike.
 *
 *   inline  one centred row (the shared footer)
 *   list    a stacked list (the Family account page, Staff menu content)
 */
export function LegalLinks({ variant = "inline", className }: { variant?: "inline" | "list"; className?: string }) {
  const pages = publishedLegalPages();
  if (pages.length === 0) return null;

  return (
    <nav aria-label="روابط فامبوك" className={className} data-legal-links>
      <ul className={cn(variant === "inline" ? "flex flex-wrap items-center justify-center gap-x-3 gap-y-1" : "flex flex-col gap-1")}>
        {pages.map((page) => (
          <li key={page.id}>
            <Link
              href={page.href}
              className={cn(
                "font-medium underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:outline-2 focus-visible:outline-ring",
                variant === "inline" ? "text-muted-foreground" : "inline-flex min-h-10 items-center text-sm text-brand-700"
              )}
              data-legal-link={page.id}
            >
              {page.title}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
