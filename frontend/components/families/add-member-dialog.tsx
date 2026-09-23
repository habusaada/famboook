"use client";

import { Info, UserPlus } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";

// Member registration has no backend endpoint yet (only the initial
// household-head registration exists). This dialog is intentionally
// non-functional — it must not fake a successful member creation.
export function AddMemberDialog() {
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button size="sm">
          <UserPlus className="size-4" />
          إضافة فرد
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إضافة فرد إلى الأسرة</DialogTitle>
          <DialogDescription>هذه الميزة قيد التطوير.</DialogDescription>
        </DialogHeader>

        <Alert>
          <Info className="size-4" />
          <AlertTitle>غير متاح حالياً</AlertTitle>
          <AlertDescription>
            إضافة أفراد جدد للأسرة تتطلب واجهة برمجية غير مُنفّذة بعد. سيتم
            تفعيل هذه الميزة في مرحلة لاحقة.
          </AlertDescription>
        </Alert>
      </DialogContent>
    </Dialog>
  );
}
