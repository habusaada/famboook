import { UsersRound } from "lucide-react";
import { cn } from "@/lib/utils";

/** The Famboook identity in the Family Portal (not a sub-brand, docs/11 §24). */
export function FamilyBrand({ size = "md", className }: { size?: "md" | "lg"; className?: string }) {
  const large = size === "lg";

  return (
    <div className={cn("flex items-center gap-2.5", className)} data-family-brand>
      <span
        className={cn("flex items-center justify-center rounded-xl bg-brand-700 text-white", large ? "size-12" : "size-9")}
        aria-hidden
      >
        <UsersRound className={large ? "size-6" : "size-[18px]"} strokeWidth={2} />
      </span>
      <span className="flex flex-col leading-none">
        <span className={cn("font-bold tracking-tight text-brand-900", large ? "text-2xl" : "text-lg")} dir="ltr">
          Famboook
        </span>
        <span className={cn("mt-1 font-medium text-brand-700", large ? "text-sm" : "text-xs")}>بوابة الأسرة</span>
      </span>
    </div>
  );
}
