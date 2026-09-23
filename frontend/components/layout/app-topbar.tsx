"use client";

import { usePathname } from "next/navigation";
import { Bell, Menu, NotebookText, Search, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { SidebarNav } from "@/components/layout/sidebar-nav";
import { navItems } from "@/lib/navigation";
import { useState } from "react";

export function AppTopbar() {
  const pathname = usePathname();
  const [mobileNavOpen, setMobileNavOpen] = useState(false);

  const currentPage =
    navItems.find((item) =>
      item.href === "/" ? pathname === "/" : pathname.startsWith(item.href)
    ) ?? navItems[0];

  return (
    <header className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b bg-background/95 px-4 shadow-sm backdrop-blur supports-backdrop-filter:bg-background/80">
      <Sheet open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
        <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="lg:hidden">
            <Menu className="size-5" />
            <span className="sr-only">فتح القائمة</span>
          </Button>
        </SheetTrigger>
        <SheetContent side="right" className="w-64 p-0">
          <SheetHeader className="h-14 flex-row items-center gap-2 space-y-0 border-b px-4">
            <span className="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
              <NotebookText className="size-4" />
            </span>
            <SheetTitle className="text-base">Famboook</SheetTitle>
          </SheetHeader>
          <SidebarNav onNavigate={() => setMobileNavOpen(false)} />
        </SheetContent>
      </Sheet>

      <h1 className="text-base font-semibold">{currentPage.label}</h1>

      <div className="ms-auto flex items-center gap-2">
        <div className="relative hidden sm:block">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <input
            type="search"
            placeholder="بحث..."
            className="h-9 w-48 rounded-md border border-input bg-background ps-8 pe-3 text-sm outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:w-64"
          />
        </div>

        <Button variant="ghost" size="icon" aria-label="الإشعارات">
          <Bell className="size-5" />
        </Button>

        <Button variant="ghost" size="icon" aria-label="الملف الشخصي">
          <UserRound className="size-5" />
        </Button>
      </div>
    </header>
  );
}
