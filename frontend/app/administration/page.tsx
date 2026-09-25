import Link from "next/link";
import { ChevronLeft, Network } from "lucide-react";
import {
  Card,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

// Operational administration. System/high administration stays in
// Filament (CLAUDE.md); this page only links staff-facing screens.
export default function AdministrationPage() {
  return (
    <div className="flex flex-col gap-5">
      <h2 className="text-2xl font-semibold tracking-tight">الإدارة</h2>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
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
      </div>
    </div>
  );
}
