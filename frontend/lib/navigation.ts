import {
  LayoutDashboard,
  Users,
  User,
  ClipboardList,
  HeartHandshake,
  HandHeart,
  BarChart3,
  Settings,
  type LucideIcon,
} from "lucide-react";

export type NavGroup = "operations" | "registry" | "cases" | "follow-up" | "system";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Shown (and the route opened) only with ANY of these permissions. */
  permissions: readonly string[];
  group: NavGroup;
};

/** Sidebar group headings, in order. "operations" has no heading. */
export const navGroups: { key: NavGroup; label: string | null }[] = [
  { key: "operations", label: null },
  { key: "registry", label: "إدارة السجل" },
  { key: "cases", label: "إدارة الحالات" },
  { key: "follow-up", label: "المتابعة" },
  { key: "system", label: "النظام" },
];

export const navItems: NavItem[] = [
  { href: "/", label: "لوحة العمليات", icon: LayoutDashboard, permissions: ["dashboard.view-operational"], group: "operations" },
  { href: "/families", label: "الأسر", icon: Users, permissions: ["family.view"], group: "registry" },
  { href: "/people", label: "الأشخاص", icon: User, permissions: ["person.view"], group: "registry" },
  { href: "/assessments", label: "التقييمات", icon: ClipboardList, permissions: ["assessment.view"], group: "cases" },
  { href: "/needs", label: "الاحتياجات", icon: HeartHandshake, permissions: ["need.view"], group: "cases" },
  { href: "/assistances", label: "المساعدات", icon: HandHeart, permissions: ["assistance.view"], group: "cases" },
  { href: "/reports", label: "التقارير", icon: BarChart3, permissions: ["report.view"], group: "follow-up" },
  {
    href: "/administration",
    label: "الإدارة",
    icon: Settings,
    // clan.view alone only feeds selectors: no administration screen.
    permissions: ["clan.manage", "system-admin.access"],
    group: "system",
  },
];

/** The nav section a path belongs to (longest matching prefix). */
export function navItemFor(pathname: string): NavItem | undefined {
  return navItems.find((item) => (item.href === "/" ? pathname === "/" : pathname.startsWith(item.href)));
}

/** Routes rendered without the Staff shell and without authentication. */
export const PUBLIC_ROUTES = ["/login", "/dev-login"] as const;

export function isPublicRoute(pathname: string): boolean {
  return PUBLIC_ROUTES.some((route) => pathname === route || pathname.startsWith(`${route}/`));
}

/** A safe in-app redirect target (never an absolute or protocol-relative URL). */
export function safeNext(next: string | null): string {
  return next && next.startsWith("/") && !next.startsWith("//") && !isPublicRoute(next) ? next : "/";
}
