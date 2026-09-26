"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { LogOut, UserRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useAuth } from "@/components/auth/auth-context";
import { ME_QUERY_KEY, logout } from "@/lib/api/auth";

/** The signed-in Staff user (name, role) and logout. */
export function UserMenu() {
  const { user } = useAuth();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);

  async function signOut() {
    setPending(true);
    // Even if the server call fails the local session state is dropped;
    // the next API call would be refused anyway.
    await logout().catch(() => undefined);
    router.replace("/login");
    queryClient.clear();
    queryClient.setQueryData(ME_QUERY_KEY, null);
  }

  return (
    <DropdownMenu dir="rtl">
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" className="h-9 gap-2 px-2" aria-label="الملف الشخصي" data-user-menu>
          <UserRound className="size-5" />
          <span className="hidden max-w-40 truncate text-sm font-medium sm:inline">{user.name}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="flex flex-col gap-0.5">
          <span className="truncate text-sm font-medium text-foreground" data-user-name>
            {user.name}
          </span>
          <span className="text-xs font-normal text-muted-foreground" data-user-role={user.role ?? ""}>
            {user.role_label ?? "بلا دور"}
          </span>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={signOut} disabled={pending} data-logout>
          <LogOut className="size-4" />
          تسجيل الخروج
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
