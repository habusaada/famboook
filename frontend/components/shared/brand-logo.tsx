import { cn } from "@/lib/utils";

/**
 * The official Famboook identity (FB-BRAND-01 / 02, docs/10 §7a), served
 * untouched from /public/brand:
 *
 *   full  FamboookLogoColoredLight.svg  432 × 144 — on light surfaces
 *         FamboookLogoColoredDark.svg   432 × 144 — on dark surfaces
 *   icon  FamboookLogoColoredIcon.svg   143.57 × 144.39 — the standalone mark
 *
 * The full logo follows the application's EXISTING theme mechanism — the
 * `.dark` class behind Tailwind's `dark:` variant (globals.css). Nothing here
 * enables a theme: while no `.dark` ancestor exists (today), the light
 * variant is the one shown; the dark one is `display: none`, so it is
 * neither visible nor in the accessibility tree.
 *
 * The artwork is rendered as an image — never redrawn, recoloured or
 * mirrored in the RTL page — and only its HEIGHT is set by the caller
 * (`className`, e.g. "h-8"): the width follows, so the aspect ratio cannot
 * be distorted. Its colours stay inside the assets; nothing here touches
 * the application palette.
 */
export const BRAND_LOGO = {
  full: { src: "/brand/FamboookLogoColoredLight.svg", darkSrc: "/brand/FamboookLogoColoredDark.svg", width: 432, height: 144 },
  icon: { src: "/brand/FamboookLogoColoredIcon.svg", darkSrc: null, width: 144, height: 144 },
} as const;

export function BrandLogo({
  variant = "full",
  className,
  alt = "Famboook",
}: {
  variant?: keyof typeof BRAND_LOGO;
  /** Set the height here (e.g. "h-8"); the width is automatic. */
  className?: string;
  /** "" when a visible name beside it already labels the brand. */
  alt?: string;
}) {
  const asset = BRAND_LOGO[variant];
  const image = (src: string, theme: "light" | "dark", visibility: string) => (
    // A static SVG from /public: next/image would add nothing to it.
    // eslint-disable-next-line @next/next/no-img-element
    <img
      key={theme}
      src={src}
      alt={alt}
      width={asset.width}
      height={asset.height}
      decoding="async"
      draggable={false}
      className={cn("w-auto max-w-full select-none", visibility, className)}
      data-brand-logo={variant}
      data-brand-theme={theme}
    />
  );

  return asset.darkSrc === null ? image(asset.src, "light", "block") : [image(asset.src, "light", "block dark:hidden"), image(asset.darkSrc, "dark", "hidden dark:block")];
}
