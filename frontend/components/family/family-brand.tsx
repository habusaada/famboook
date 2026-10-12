import { BrandLogo } from "@/components/shared/brand-logo";
import { cn } from "@/lib/utils";

/**
 * The Famboook identity in the Family Portal (not a sub-brand, docs/11 §24):
 * the OFFICIAL full logo (BrandLogo, FB-BRAND-01 / 02). The asset is
 * rendered as an image, never redrawn or recoloured: only its height is set,
 * the width follows, so the aspect ratio cannot be distorted. The logo is
 * artwork and is not mirrored in the RTL page.
 *
 *   lg  the public pages (activation, login, password reset): centred
 *   md  the authenticated header: compact, at the start of the bar
 *
 * FB-BRAND-02: the logo stands alone everywhere — the authentication pages,
 * the portal header, the Family Card and the public verification page.
 */
export function FamilyBrand({ size = "md", className }: { size?: "md" | "lg"; className?: string }) {
  const large = size === "lg";

  return (
    <div className={cn("flex flex-col", large ? "items-center gap-2.5" : "items-start gap-1.5", className)} data-family-brand>
      <BrandLogo className={large ? "h-12" : "h-8"} />
    </div>
  );
}
