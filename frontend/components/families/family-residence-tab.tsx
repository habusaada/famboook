import {
  Card,
  CardAction,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { useAuth } from "@/components/auth/auth-context";
import { InfoRow } from "@/components/families/family-overview";
import {
  EditCurrentResidenceDialog,
  EditDisplacementDialog,
} from "@/components/families/edit-residence-dialogs";
import type { FamilyDetail } from "@/lib/types/api/family";
import { displacementStatusLabel } from "@/lib/utils/displacement";

const NOT_RECORDED = "غير مسجّل";

export function FamilyResidenceTab({ family }: { family: FamilyDetail }) {
  const { can } = useAuth();
  const residence = family.residence;

  if (!residence) {
    return (
      <p className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
        لا يوجد سكن حالي مسجّل
      </p>
    );
  }

  const isDisplaced = residence.displacement_status === "DISPLACED";

  return (
    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
      <Card size="sm">
        <CardHeader>
          <CardTitle>النزوح</CardTitle>
          <CardDescription>السكن الأصلي قبل النزوح وحالة النزوح الحالية</CardDescription>
          <CardAction>
            {can("residence.update") && <EditDisplacementDialog familyCode={family.family_code} residence={residence} />}
          </CardAction>
        </CardHeader>
        <CardContent>
          <InfoRow
            label="السكن الأصلي"
            value={residence.original_residence_text ?? NOT_RECORDED}
          />
          <InfoRow
            label="حالة النزوح"
            value={displacementStatusLabel(residence.displacement_status)}
          />
          {/* A location only means something for a displaced family. */}
          {isDisplaced && (
            <InfoRow
              label="مكان النزوح الحالي"
              value={residence.displacement_location_text ?? NOT_RECORDED}
            />
          )}
        </CardContent>
      </Card>

      <Card size="sm">
        <CardHeader>
          <CardTitle>السكن الحالي</CardTitle>
          <CardDescription>موقع إقامة الأسرة الحالي</CardDescription>
          <CardAction>
            {can("residence.update") && <EditCurrentResidenceDialog familyCode={family.family_code} residence={residence} />}
          </CardAction>
        </CardHeader>
        <CardContent>
          <InfoRow label="المحافظة" value={residence.governorate} />
          <InfoRow label="المدينة" value={residence.city} />
          {residence.area && <InfoRow label="المنطقة" value={residence.area} />}
          {residence.neighborhood && (
            <InfoRow label="الحي" value={residence.neighborhood} />
          )}
          {residence.address_text && (
            <InfoRow label="العنوان التفصيلي" value={residence.address_text} />
          )}
          {residence.started_at && (
            <InfoRow label="تاريخ بدء السكن" value={residence.started_at} ltr />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
