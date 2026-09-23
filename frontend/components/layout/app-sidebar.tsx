import Link from "next/link";
import { NotebookText } from "lucide-react";
import { SidebarNav } from "@/components/layout/sidebar-nav";

export function AppSidebar() {
  return (
    <aside className="hidden w-64 shrink-0 flex-col border-e bg-sidebar shadow-[1px_0_2px_0_rgb(0_0_0_/_0.03)] lg:flex">
      <div className="flex h-14 items-center gap-2 border-b px-4">
        <Link href="/" className="flex items-center gap-2 font-semibold">
          <span className="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
            <NotebookText className="size-4" />
          </span>
          <span className="text-base">Famboook</span>
        </Link>
      </div>
      <SidebarNav />
    </aside>
  );
}
