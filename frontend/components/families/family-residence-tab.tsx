import { MapPinOff } from "lucide-react";
import { useAuth } from "@/components/auth/auth-context";
import { InfoRow, ResidenceSummary } from "@/components/families/family-overview";
import {
  EditCurrentResidenceDialog,
  EditDisplacementDialog,
} from "@/components/families/edit-residence-dialogs";
import { AppCard } from "@/components/shared/app-card";
import { EmptyState } from "@/components/shared/empty-state";
import { SectionHeader } from "@/components/shared/page-layout";
import type { FamilyDetail } from "@/lib/types/api/family";

const NOT_RECORDED = "غير مسجّل";

export function FamilyResidenceTab({ family }: { family: FamilyDetail }) {
  const { can } = useAuth();
  const residence = family.residence;

  if (!residence) {
    return (
      <AppCard padded={false}>
        <EmptyState icon={MapPinOff} title="لا يوجد سكن حالي مسجّل" />
      </AppCard>
    );
  }

  const canEdit = can("residence.update");

  return (
    <div className="flex flex-col gap-4">
      <AppCard>
        <SectionHeader
          title="السكن والنزوح"
          description="السكن الأصلي قبل النزوح، وحالة النزوح ومكانه، وعنوان الإقامة الحالي كما ورد في الاستمارة"
        />
        <div className="mt-3">
          <ResidenceSummary
            residence={residence}
            displacementAction={canEdit ? <EditDisplacementDialog familyCode={family.family_code} residence={residence} /> : undefined}
            residenceAction={canEdit ? <EditCurrentResidenceDialog familyCode={family.family_code} residence={residence} /> : undefined}
          />
        </div>
      </AppCard>

      <AppCard>
        <SectionHeader title="تفاصيل العنوان الحالي" description="الحقول الجغرافية اختيارية؛ النص المسجّل قد يكون القيمة المعتمدة" />
        <div className="mt-2 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
          <InfoRow label="المحافظة" value={residence.governorate ?? NOT_RECORDED} />
          <InfoRow label="المدينة" value={residence.city ?? NOT_RECORDED} />
          <InfoRow label="المنطقة" value={residence.area ?? NOT_RECORDED} />
          <InfoRow label="الحي" value={residence.neighborhood ?? NOT_RECORDED} />
          <InfoRow label="العنوان التفصيلي" value={residence.address_text ?? NOT_RECORDED} />
          <InfoRow label="تاريخ بدء السكن" value={residence.started_at ?? NOT_RECORDED} ltr={!!residence.started_at} />
        </div>
      </AppCard>
    </div>
  );
}
