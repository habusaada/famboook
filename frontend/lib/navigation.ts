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

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
};

export const navItems: NavItem[] = [
  { href: "/", label: "لوحة التحكم", icon: LayoutDashboard },
  { href: "/families", label: "العائلات", icon: Users },
  { href: "/people", label: "الأشخاص", icon: User },
  { href: "/assessments", label: "التقييمات", icon: ClipboardList },
  { href: "/needs", label: "الاحتياجات", icon: HeartHandshake },
  { href: "/assistance", label: "المساعدات", icon: HandHeart },
  { href: "/reports", label: "التقارير", icon: BarChart3 },
  { href: "/administration", label: "الإدارة", icon: Settings },
];
