"use client";

import Link from "next/link";
import { AlertCircle, ArrowRight, Lock, SearchX } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { ApiError } from "@/lib/api/client";

// Small building blocks shared by the assessment screens.

export function BackToAssessments({ familyCode }: { familyCode: string }) {
  return (
    <Button variant="ghost" size="sm" className="-ms-2 w-fit gap-1.5 text-muted-foreground" asChild>
      <Link href={`/families/${encodeURIComponent(familyCode)}?tab=assessments`}>
        <ArrowRight className="size-4" />
        تقييمات الأسرة <span dir="ltr">{familyCode}</span>
      </Link>
    </Button>
  );
}

export function AssessmentLoadError({ error }: { error: unknown }) {
  if (error instanceof ApiError && error.status === 403) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
        <Lock className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">لا تملك صلاحية عرض التقييمات.</p>
      </div>
    );
  }

  if (error instanceof ApiError && error.status === 404) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-16 text-center">
        <SearchX className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">لم يتم العثور على هذا التقييم.</p>
      </div>
    );
  }

  return (
    <Alert variant="destructive">
      <AlertCircle className="size-4" />
      <AlertTitle>تعذّر تحميل التقييم</AlertTitle>
      <AlertDescription>حدث خطأ أثناء الاتصال بالخادم.</AlertDescription>
    </Alert>
  );
}

/** Completion is irreversible in V1, so it is always confirmed first. */
export function ConfirmCompleteDialog({
  open,
  onOpenChange,
  onConfirm,
  assessedCount,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  assessedCount: number;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إكمال التقييم</DialogTitle>
          <DialogDescription>
            سيُحفظ التقييم ({assessedCount} من المجالات مُقيَّمة) كسجل تاريخي مكتمل، ولا
            يمكن تعديله أو إعادته إلى مسودة بعد ذلك. عند تغيّر وضع الأسرة يُنشأ تقييم جديد.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            إلغاء
          </Button>
          <Button type="button" onClick={onConfirm}>
            تأكيد الإكمال
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export const ASSESSMENT_STATUS_MESSAGES = {
  403: "لا تملك صلاحية تعديل أو إكمال التقييمات.",
  404: "لم يتم العثور على هذا التقييم.",
  409: "هذا التقييم مكتمل ولا يمكن تعديله.",
};
