import { cn } from "@/lib/utils";

/** The official Famboook logo, served untouched from /public (648 × 82.79). */
export const FAMILY_LOGO_SRC = "/brand/famboook-logo.svg";

/**
 * The Famboook identity in the Family Portal (not a sub-brand, docs/11 §24):
 * the OFFICIAL logo file, then "بوابة الأسرة". The asset is rendered as an
 * image, never redrawn or recoloured: only its height is set, the width
 * follows, so the aspect ratio cannot be distorted. The wordmark is Latin
 * artwork and is not mirrored in the RTL page.
 *
 *   lg  the public pages (activation, login, password reset): centred
 *   md  the authenticated header: compact, at the start of the bar
 */
export function FamilyBrand({ size = "md", className }: { size?: "md" | "lg"; className?: string }) {
  const large = size === "lg";

  return (
    <div className={cn("flex flex-col", large ? "items-center gap-2.5" : "items-start gap-1.5", className)} data-family-brand>
      {/* A static SVG from /public: next/image would add nothing to it. */}
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={FAMILY_LOGO_SRC}
        alt="Famboook"
        width={648}
        height={83}
        decoding="async"
        className={cn("block w-auto max-w-full select-none", large ? "h-8" : "h-[18px]")}
        draggable={false}
      />
      <span className={cn("leading-none font-medium text-brand-700", large ? "text-sm" : "text-[11px]")}>بوابة الأسرة</span>
    </div>
  );
}
