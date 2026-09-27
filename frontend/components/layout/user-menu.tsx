"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { LogOut } from "lucide-react";
import { Initials } from "@/components/shared/initials";
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
        <Button variant="ghost" className="h-10 gap-2.5 px-2 hover:bg-surface-hover active:bg-surface-pressed" aria-label={`الحساب: ${user.name}`} data-user-menu>
          <Initials name={user.name} />
          {/* The signed-in user's own name and role (never the email). */}
          <span className="hidden min-w-0 flex-col items-start leading-tight sm:flex">
            <bdi className="max-w-44 truncate text-sm font-medium text-foreground">{user.name}</bdi>
            <span className="text-xs font-normal text-muted-foreground">{user.role_label ?? "بلا دور"}</span>
          </span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="flex flex-col gap-0.5">
          <bdi className="truncate text-sm font-medium text-foreground" data-user-name>
            {user.name}
          </bdi>
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
