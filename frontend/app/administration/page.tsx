"use client";

import Link from "next/link";
import { ChevronLeft, ExternalLink, Network, UserCog } from "lucide-react";
import { Card, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/components/auth/auth-context";

// Filament base URL: https://admin.famboook.com/admin in the Pilot (docs/08);
// locally the panel is served by the API host.
const ADMIN_URL =
  process.env.NEXT_PUBLIC_ADMIN_URL ?? `${process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000"}/admin`;

// Operational administration. System/high administration (Staff users)
// lives in Filament (CLAUDE.md, docs/06 §59c); this page only links to it.
export default function AdministrationPage() {
  const { can } = useAuth();

  return (
    <div className="flex flex-col gap-5">
      <h2 className="text-2xl font-semibold tracking-tight">الإدارة</h2>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {can("clan.manage") && (
          <Link href="/administration/clans" className="group">
            <Card className="transition-colors group-hover:bg-muted/50">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Network className="size-5 text-muted-foreground" />
                  العشائر والعائلات
                  <ChevronLeft className="ms-auto size-4 text-muted-foreground" />
                </CardTitle>
                <CardDescription>
                  إدارة العشائر والعائلات ومجموعات الفروع والفروع: الإضافة والتعديل
                  والتفعيل وإلغاء التفعيل والترتيب.
                </CardDescription>
              </CardHeader>
            </Card>
          </Link>
        )}
        {can("system-admin.access") && (
          <a href={`${ADMIN_URL}/users`} className="group" data-filament-link>
            <Card className="transition-colors group-hover:bg-muted/50">
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <UserCog className="size-5 text-muted-foreground" />
                  مستخدمو الطاقم
                  <ExternalLink className="ms-auto size-4 text-muted-foreground" />
                </CardTitle>
                <CardDescription>
                  إنشاء حسابات الطاقم وتعديلها وإسناد الأدوار والتفعيل والإيقاف وتعيين كلمة
                  مرور مؤقتة — في لوحة إدارة النظام.
                </CardDescription>
              </CardHeader>
            </Card>
          </a>
        )}
      </div>
    </div>
  );
}
