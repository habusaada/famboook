import { cn } from "@/lib/utils";

/**
 * The official Famboook identity (FB-BRAND-01, docs/10 "Brand assets"),
 * served untouched from /public/brand:
 *
 *   full  FamboookLogoColored.svg      432 × 144.39 — the wordmark lockup
 *   icon  FamboookLogoColoredIcon.svg  143.57 × 144.39 — the standalone mark
 *
 * The artwork is rendered as an image — never redrawn, recoloured or
 * mirrored in the RTL page — and only its HEIGHT is set by the caller
 * (`className`, e.g. "h-7"): the width follows, so the aspect ratio cannot
 * be distorted. Its colours stay inside the asset; nothing here touches
 * the application palette. The full logo is readable from about 28 px tall.
 */
export const BRAND_LOGO = {
  full: { src: "/brand/FamboookLogoColored.svg", width: 432, height: 144 },
  icon: { src: "/brand/FamboookLogoColoredIcon.svg", width: 144, height: 144 },
} as const;

export function BrandLogo({
  variant = "full",
  className,
  alt = "Famboook",
}: {
  variant?: keyof typeof BRAND_LOGO;
  /** Set the height here (e.g. "h-7"); the width is automatic. */
  className?: string;
  /** "" when a visible name beside it already labels the brand. */
  alt?: string;
}) {
  const asset = BRAND_LOGO[variant];

  return (
    // A static SVG from /public: next/image would add nothing to it.
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={asset.src}
      alt={alt}
      width={asset.width}
      height={asset.height}
      decoding="async"
      draggable={false}
      className={cn("block w-auto max-w-full select-none", className)}
      data-brand-logo={variant}
    />
  );
}
