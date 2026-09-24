"use client";

import { AlertCircle, Pencil } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { DialogFooter, DialogTrigger } from "@/components/ui/dialog";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";

// Small building blocks shared by the profile edit dialogs.

export function FieldLabel({
  htmlFor,
  optional,
  children,
}: {
  htmlFor: string;
  optional?: boolean;
  children: React.ReactNode;
}) {
  return (
    <Label htmlFor={htmlFor}>
      {children}
      {optional && (
        <span className="text-xs font-normal text-muted-foreground">
          (اختياري)
        </span>
      )}
    </Label>
  );
}

export function FieldError({ message }: { message?: string }) {
  return message ? <p className="text-xs text-destructive">{message}</p> : null;
}

export function EditTrigger() {
  return (
    <DialogTrigger asChild>
      <Button variant="outline" size="sm">
        <Pencil className="size-4" />
        تعديل
      </Button>
    </DialogTrigger>
  );
}

export function SaveError({ message }: { message: string | null }) {
  if (!message) return null;
  return (
    <Alert variant="destructive">
      <AlertCircle className="size-4" />
      <AlertTitle>تعذّر حفظ التعديلات</AlertTitle>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  );
}

export function EditDialogFooter({
  formId,
  isPending,
  onCancel,
}: {
  formId: string;
  isPending: boolean;
  onCancel: () => void;
}) {
  return (
    <DialogFooter>
      <Button type="button" variant="outline" onClick={onCancel} disabled={isPending}>
        إلغاء
      </Button>
      <Button type="submit" form={formId} disabled={isPending}>
        {isPending ? "جارٍ الحفظ..." : "حفظ التعديلات"}
      </Button>
    </DialogFooter>
  );
}
