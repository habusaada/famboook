"use client";

import { birthDateLabel } from "@/lib/utils/date";
import { useAuth } from "@/components/auth/auth-context";
import { EditFamilyRegistrationDialog } from "@/components/families/edit-family-registration-dialog";
import { FamilyStatusBadge } from "@/components/families/family-status-badge";
import { EditPersonDialog } from "@/components/people/edit-person-dialog";
import { Code, DetailItem, DetailList, Panel, SectionHeader } from "@/components/shared/page-layout";
import { StatusBadge } from "@/components/shared/status-badge";
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

// Established Arabic wording: "غير مسجّل" = a value that was not recorded;
// "غير محدد" / "غير معروف" keep their domain meanings (branch/status not
// set, unknown date of birth).
const NOT_RECORDED = "غير مسجّل";

/** Label/value row, also used by the residence tab. */
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
    <div className="flex items-baseline justify-between gap-4 border-b border-border/70 py-2 text-sm last:border-0">
      <span className="text-[13px] font-medium text-muted-foreground">{label}</span>
      <span className="text-end font-medium" dir={ltr ? "ltr" : undefined}>
        {value}
      </span>
    </div>
  );
}

function Missing({ children = NOT_RECORDED }: { children?: string }) {
  return <span className="font-normal text-subtle-foreground">{children}</span>;
}

export function FamilyOverview({ family }: { family: FamilyDetail }) {
  const { can } = useAuth();
  const head = family.members.find((m) => m.is_household_head);
  // The head is a Person: contact details, life status and the edit
  // dialog come from the existing Person endpoint.
  const { data: headPersonData } = usePerson(head?.person_code ?? "");
  const headPerson = headPersonData?.data;
  const residence = family.residence;

  return (
    <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
      <Panel className="xl:col-span-7">
        <SectionHeader
          title="معلومات الأسرة"
          description="بيانات تسجيل الأسرة في السجل"
          action={can("family.update") ? <EditFamilyRegistrationDialog family={family} /> : undefined}
        />
        <DetailList className="mt-3">
          <DetailItem label="رب الأسرة">{head ? head.full_name : <Missing>غير محدد</Missing>}</DetailItem>
          <DetailItem label="رقم الأسرة">
            <Code>{family.family_code}</Code>
          </DetailItem>
          <DetailItem label="الفرع">
            {family.branch ? family.branch.name + (family.branch.is_active ? "" : " (غير مفعّل)") : <Missing>غير محدد</Missing>}
          </DetailItem>
          <DetailItem label="العشيرة / العائلة">
            {family.clan ? family.clan.name + (family.clan.is_active ? "" : " (غير مفعّلة)") : <Missing />}
          </DetailItem>
          {family.branch?.group.name && <DetailItem label="مجموعة الفروع">{family.branch.group.name}</DetailItem>}
          <DetailItem label="عدد الأفراد الحاليين">
            <span className="tabular-nums">{family.member_count.toLocaleString("ar")}</span>
          </DetailItem>
          <DetailItem label="الحالة">
            <FamilyStatusBadge status={family.status} />
          </DetailItem>
          <DetailItem label="رقم النموذج الورقي" ltr={!!family.paper_form_no}>
            {family.paper_form_no ?? <Missing />}
          </DetailItem>
          <DetailItem label="تاريخ التسجيل" ltr={!!family.registration_date}>
            {family.registration_date ?? <Missing />}
          </DetailItem>
          <DetailItem label="مصدر التسجيل">
            {family.registration_source
              ? (registrationSourceLabels[family.registration_source] ?? family.registration_source)
              : <Missing />}
          </DetailItem>
        </DetailList>
      </Panel>

      <Panel className="xl:col-span-5">
        <SectionHeader
          title="رب الأسرة"
          description="الشخص المسؤول عن الأسرة حالياً"
          action={
            headPerson && can("person.update") ? (
              <EditPersonDialog
                person={headPerson}
                title="تعديل بيانات رب الأسرة"
                description="تصحيح البيانات الأساسية لرب الأسرة الحالي. لا يغيّر من هو رب الأسرة ولا عضوية الأسرة."
              />
            ) : undefined
          }
        />
        {head ? (
          <DetailList className="mt-3 sm:grid-cols-1">
            <DetailItem label="الاسم الكامل">{head.full_name}</DetailItem>
            <DetailItem label="رقم الفرد">
              <Code>{head.person_code}</Code>
            </DetailItem>
            <DetailItem label="الجنس">{head.gender === "MALE" ? "ذكر" : "أنثى"}</DetailItem>
            <DetailItem label="تاريخ الميلاد" ltr={!!head.birth_date}>
              {head.birth_date ? birthDateLabel(head.birth_date) : <Missing>{birthDateLabel(null)}</Missing>}
            </DetailItem>
            {headPerson && (
              <>
                <DetailItem label="الحالة">{lifeStatusLabel(headPerson.life_status)}</DetailItem>
                <DetailItem label="الجوال الأساسي" ltr={!!headPerson.mobile}>
                  {headPerson.mobile ?? <Missing />}
                </DetailItem>
                {headPerson.alternate_mobile && (
                  <DetailItem label="الجوال البديل" ltr>
                    {headPerson.alternate_mobile}
                  </DetailItem>
                )}
                {headPerson.alternate_mobile && headPerson.alternate_mobile_owner_relation && (
                  <DetailItem label="صاحب الرقم البديل / صلته">{headPerson.alternate_mobile_owner_relation}</DetailItem>
                )}
              </>
            )}
          </DetailList>
        ) : (
          <p className="mt-3 text-sm text-muted-foreground">لم يتم تحديد رب الأسرة بعد</p>
        )}
      </Panel>

      <Panel className="xl:col-span-12">
        <SectionHeader title="السكن والنزوح" description="السكن الأصلي قبل النزوح، وحالة النزوح، والسكن الحالي — التعديل من تبويب «السكن»" />
        {residence ? (
          <DetailList className="mt-3 lg:grid-cols-3">
            <DetailItem label="السكن الأصلي (قبل النزوح)">{residence.original_residence_text ?? <Missing />}</DetailItem>
            <DetailItem label="حالة النزوح">
              {residence.displacement_status === "DISPLACED" ? (
                <StatusBadge tone="warning">نازحة</StatusBadge>
              ) : residence.displacement_status === "NOT_DISPLACED" ? (
                displacementStatusLabel(residence.displacement_status)
              ) : (
                <Missing>{displacementStatusLabel(null)}</Missing>
              )}
            </DetailItem>
            {residence.displacement_status === "DISPLACED" && (
              <DetailItem label="مكان النزوح الحالي">{residence.displacement_location_text ?? <Missing />}</DetailItem>
            )}
            <DetailItem label="المحافظة">{residence.governorate ?? <Missing />}</DetailItem>
            <DetailItem label="المدينة">{residence.city ?? <Missing />}</DetailItem>
            {residence.area && <DetailItem label="المنطقة">{residence.area}</DetailItem>}
          </DetailList>
        ) : (
          <p className="mt-3 text-sm text-muted-foreground">لا يوجد سكن حالي مسجّل</p>
        )}
      </Panel>

      {family.notes && (
        <Panel className="xl:col-span-12">
          <SectionHeader title="ملاحظات" />
          <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{family.notes}</p>
        </Panel>
      )}
    </div>
  );
}
