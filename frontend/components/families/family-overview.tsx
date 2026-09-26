"use client";

import { useAuth } from "@/components/auth/auth-context";
import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { EditFamilyRegistrationDialog } from "@/components/families/edit-family-registration-dialog";
import { EditPersonDialog } from "@/components/people/edit-person-dialog";
import { usePerson } from "@/lib/api/people";
import type { FamilyDetail } from "@/lib/types/api/family";
import { displacementStatusLabel } from "@/lib/utils/displacement";
import { lifeStatusLabel } from "@/lib/utils/life-status";

const registrationSourceLabels: Record<string, string> = {
  PAPER_FORM: "نموذج ورقي",
  MANUAL_ENTRY: "إدخال يدوي",
  IMPORT: "استيراد بيانات",
  VERIFIED_SOURCE: "مصدر موثّق",
};

export function InfoRow({
  label,
  value,
  ltr,
}: {
  label: string;
  value: string;
  ltr?: boolean;
}) {
  return (
    <div className="flex items-center justify-between gap-4 border-b py-1.5 text-sm last:border-0">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium" dir={ltr ? "ltr" : undefined}>
        {value}
      </span>
    </div>
  );
}

export function FamilyOverview({ family }: { family: FamilyDetail }) {
  const { can } = useAuth();
  const head = family.members.find((m) => m.is_household_head);
  // The head is a Person: contact details, life status and the edit
  // dialog come from the existing Person endpoint.
  const { data: headPersonData } = usePerson(head?.person_code ?? "");
  const headPerson = headPersonData?.data;

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
      <Card size="sm">
        <CardHeader>
          <CardTitle>معلومات التسجيل</CardTitle>
          <CardDescription>بيانات تسجيل الأسرة في السجل</CardDescription>
          {can("family.update") && (
            <CardAction>
              <EditFamilyRegistrationDialog family={family} />
            </CardAction>
          )}
        </CardHeader>
        <CardContent>
          {family.clan && (
            <InfoRow
              label="العشيرة / العائلة"
              value={family.clan.name + (family.clan.is_active ? "" : " (غير مفعّلة)")}
            />
          )}
          <InfoRow
            label="الفرع"
            value={
              family.branch
                ? family.branch.name + (family.branch.is_active ? "" : " (غير مفعّل)")
                : "غير محدد"
            }
          />
          {family.branch?.group.name && (
            <InfoRow label="مجموعة الفروع" value={family.branch.group.name} />
          )}
          {family.registration_date && (
            <InfoRow
              label="تاريخ التسجيل"
              value={family.registration_date}
              ltr
            />
          )}
          {family.registration_source && (
            <InfoRow
              label="مصدر التسجيل"
              value={
                registrationSourceLabels[family.registration_source] ??
                family.registration_source
              }
            />
          )}
          {family.paper_form_no && (
            <InfoRow
              label="رقم النموذج الورقي"
              value={family.paper_form_no}
              ltr
            />
          )}
          {family.updated_at && (
            <InfoRow
              label="آخر تحديث"
              value={new Date(family.updated_at).toLocaleString("ar", {
                dateStyle: "medium",
                timeStyle: "short",
              })}
            />
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>رب الأسرة</CardTitle>
          <CardDescription>الشخص المسؤول عن الأسرة حالياً</CardDescription>
          {headPerson && can("person.update") && (
            <CardAction>
              <EditPersonDialog
                person={headPerson}
                title="تعديل بيانات رب الأسرة"
                description="تصحيح البيانات الأساسية لرب الأسرة الحالي. لا يغيّر من هو رب الأسرة ولا عضوية الأسرة."
              />
            </CardAction>
          )}
        </CardHeader>
        <CardContent>
          {head ? (
            <>
              <InfoRow label="الاسم الكامل" value={head.full_name} />
              <InfoRow label="رقم الفرد" value={head.person_code} ltr />
              <InfoRow
                label="الجنس"
                value={head.gender === "MALE" ? "ذكر" : "أنثى"}
              />
              {head.birth_date && (
                <InfoRow label="تاريخ الميلاد" value={head.birth_date} ltr />
              )}
              {headPerson && (
                <>
                  <InfoRow label="الحالة" value={lifeStatusLabel(headPerson.life_status)} />
                  {headPerson.mobile && (
                    <InfoRow label="الجوال الأساسي" value={headPerson.mobile} ltr />
                  )}
                  {headPerson.alternate_mobile && (
                    <InfoRow label="الجوال البديل" value={headPerson.alternate_mobile} ltr />
                  )}
                  {headPerson.alternate_mobile && headPerson.alternate_mobile_owner_relation && (
                    <InfoRow
                      label="صاحب الرقم البديل / صلته"
                      value={headPerson.alternate_mobile_owner_relation}
                    />
                  )}
                </>
              )}
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              لم يتم تحديد رب الأسرة بعد
            </p>
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>السكن الحالي</CardTitle>
          <CardDescription>موقع إقامة الأسرة</CardDescription>
        </CardHeader>
        <CardContent>
          {family.residence ? (
            <>
              <InfoRow label="المحافظة" value={family.residence.governorate} />
              <InfoRow label="المدينة" value={family.residence.city} />
              {family.residence.area && (
                <InfoRow label="المنطقة" value={family.residence.area} />
              )}
              {family.residence.displacement_status && (
                <InfoRow
                  label="حالة النزوح"
                  value={displacementStatusLabel(family.residence.displacement_status)}
                />
              )}
            </>
          ) : (
            <p className="text-sm text-muted-foreground">
              لا يوجد سكن حالي مسجّل
            </p>
          )}
        </CardContent>
      </Card>

      {family.notes && (
        <Card size="sm">
          <CardHeader>
            <CardTitle>ملاحظات</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">{family.notes}</p>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
