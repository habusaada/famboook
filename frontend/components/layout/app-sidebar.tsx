import Link from "next/link";
import { BrandLogo } from "@/components/shared/brand-logo";
import { SidebarNav } from "@/components/layout/sidebar-nav";

export function AppSidebar() {
  return (
    // Full page height (border/background continue), content sticky.
    <aside className="relative z-20 hidden w-64 shrink-0 border-e border-stroke-subtle bg-surface-1 shadow-e1 lg:block">
      <div className="sticky top-0 flex h-svh flex-col">
        <div className="flex h-14 items-center border-b border-stroke-subtle px-5">
          <Link href="/dashboard" className="flex items-center gap-2.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
            <BrandLogo className="h-8" />
            <span className="text-[11px] leading-tight text-subtle-foreground">بوابة الطاقم</span>
          </Link>
        </div>
        <SidebarNav />
      </div>
    </aside>
  );
}
