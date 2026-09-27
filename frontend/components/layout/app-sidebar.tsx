import Link from "next/link";
import { NotebookText } from "lucide-react";
import { SidebarNav } from "@/components/layout/sidebar-nav";

export function AppSidebar() {
  return (
    // Full page height (border/background continue), content sticky.
    <aside className="relative z-20 hidden w-64 shrink-0 border-e border-stroke-subtle bg-surface-1 shadow-e1 lg:block">
      <div className="sticky top-0 flex h-svh flex-col">
        <div className="flex h-14 items-center border-b border-stroke-subtle px-5">
          <Link href="/" className="flex items-center gap-2.5 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
            <span className="flex size-8 items-center justify-center rounded-lg bg-brand-700 text-white shadow-e1">
              <NotebookText className="size-4" strokeWidth={2} />
            </span>
            <span className="flex flex-col leading-tight">
              <span className="text-[15px] font-bold text-brand-900">Famboook</span>
              <span className="text-[11px] text-subtle-foreground">بوابة الطاقم</span>
            </span>
          </Link>
        </div>
        <SidebarNav />
      </div>
    </aside>
  );
}
