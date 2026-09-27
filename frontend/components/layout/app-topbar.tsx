"use client";

import { usePathname } from "next/navigation";
import { Menu, NotebookText } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { SidebarNav } from "@/components/layout/sidebar-nav";
import { UserMenu } from "@/components/layout/user-menu";
import { navItemFor, navItems } from "@/lib/navigation";
import { useState } from "react";

export function AppTopbar() {
  const pathname = usePathname();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  const currentPage = navItemFor(pathname) ?? navItems[0];

  return (
    <header className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b border-stroke-subtle bg-surface-1 px-4 md:px-6 xl:px-8">
      <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
        <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="lg:hidden">
            <Menu className="size-5" />
            <span className="sr-only">فتح القائمة</span>
          </Button>
        </SheetTrigger>
        <SheetContent side="right" className="w-64 p-0">
          <SheetHeader className="h-14 flex-row items-center gap-2.5 space-y-0 border-b px-5">
            <span className="flex size-8 items-center justify-center rounded-md bg-brand-700 text-white">
              <NotebookText className="size-4" />
            </span>
            <SheetTitle className="text-[15px] text-brand-900">Famboook</SheetTitle>
          </SheetHeader>
          <SidebarNav onNavigate={() => setMobileNavOpen(false)} />
        </SheetContent>
      </Sheet>

      {/* Section name only; each page's own header carries the h1. */}
      <span className="text-sm font-medium text-muted-foreground">{currentPage.label}</span>

      {/* Global search and notifications are not built yet, so no
          placeholder controls are shown. */}
      <div className="ms-auto flex items-center">
        <UserMenu />
      </div>
    </header>
  );
}
