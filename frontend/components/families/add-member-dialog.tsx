"use client";

import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { UserPlus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { relationshipLabels, type RelationshipType } from "@/lib/types/family";

type AddMemberFormValues = {
  fullName: string;
  relationship: RelationshipType;
  gender: "MALE" | "FEMALE";
  birthDate: string;
};

export function AddMemberDialog() {
  const [open, setOpen] = useState(false);
  const { register, control, handleSubmit, reset } =
    useForm<AddMemberFormValues>({
      defaultValues: { relationship: "SON", gender: "MALE" },
    });

  function onSubmit(values: AddMemberFormValues) {
    // No backend connection yet — this is a visual mock only.
    console.log("Add family member (mock):", values);
    reset();
    setOpen(false);
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm">
          <UserPlus className="size-4" />
          إضافة فرد
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>إضافة فرد إلى الأسرة</DialogTitle>
          <DialogDescription>
            معاينة مرئية فقط — لا يوجد اتصال بالخادم بعد.
          </DialogDescription>
        </DialogHeader>

        <form
          id="add-member-form"
          onSubmit={handleSubmit(onSubmit)}
          className="flex flex-col gap-4"
        >
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="member-fullName">الاسم الكامل</Label>
            <Input
              id="member-fullName"
              {...register("fullName", { required: true })}
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="member-relationship">صلة القرابة</Label>
              <Controller
                control={control}
                name="relationship"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="member-relationship">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(relationshipLabels).map(
                        ([value, label]) => (
                          <SelectItem key={value} value={value}>
                            {label}
                          </SelectItem>
                        )
                      )}
                    </SelectContent>
                  </Select>
                )}
              />
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="member-gender">الجنس</Label>
              <Controller
                control={control}
                name="gender"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="member-gender">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="MALE">ذكر</SelectItem>
                      <SelectItem value="FEMALE">أنثى</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="member-birthDate">تاريخ الميلاد</Label>
            <Input
              id="member-birthDate"
              type="date"
              {...register("birthDate", { required: true })}
            />
          </div>
        </form>

        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            إلغاء
          </Button>
          <Button type="submit" form="add-member-form">
            إضافة
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
