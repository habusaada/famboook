import {
  LayoutDashboard,
  Users,
  User,
  ClipboardList,
  ClipboardPen,
  HeartHandshake,
  HandHeart,
  Megaphone,
  BellRing,
  BarChart3,
  Settings,
  SearchCheck,
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
  // PWA-5d: the Staff review workspace (change-request.view — SUPER_ADMIN,
  // ADMINISTRATOR, REVIEWER). Also guards /change-requests/* in AuthGate.
  { href: "/change-requests", label: "طلبات تحديث البيانات", icon: ClipboardPen, permissions: ["change-request.view"], group: "registry" },
  // FB-CIVIL-02A: the Civil Registry Lookup (the page itself says when the service is off for the role).
  { href: "/civil-registry/lookup", label: "الاستعلام عن السجل المدني", icon: SearchCheck, permissions: ["civil-lookup.search"], group: "registry" },
  { href: "/assessments", label: "التقييمات", icon: ClipboardList, permissions: ["assessment.view"], group: "cases" },
  { href: "/needs", label: "الاحتياجات", icon: HeartHandshake, permissions: ["need.view"], group: "cases" },
  { href: "/assistances", label: "المساعدات", icon: HandHeart, permissions: ["assistance.view"], group: "cases" },
  { href: "/reports", label: "التقارير", icon: BarChart3, permissions: ["report.view"], group: "follow-up" },
  // FB-NOTIFY-01C.3c: the sender's own announcements (announcement.view —
  // SUPER_ADMIN, ADMINISTRATOR). Also guards /announcements/* in AuthGate.
  { href: "/announcements", label: "الإعلانات", icon: Megaphone, permissions: ["announcement.view"], group: "follow-up" },
  // FB-NOTIFY-01E.3-F3.3: every follow-up for central Staff
  // (notification-followup.view — SUPER_ADMIN, ADMINISTRATOR). Also guards
  // /followups/* in AuthGate.
  { href: "/followups", label: "المتابعات", icon: BellRing, permissions: ["notification-followup.view"], group: "follow-up" },
  {
    href: "/administration",
    label: "الإدارة",
    icon: Settings,
    // clan.view alone only feeds selectors: no administration screen.
    permissions: ["clan.manage", "coordinator-scope.manage", "system-admin.access", "civil-import.view", "civil-lookup.usage.view"],
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
