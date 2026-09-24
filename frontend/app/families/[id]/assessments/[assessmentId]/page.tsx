import { AssessmentDetailView } from "@/components/assessments/assessment-detail-view";

export default async function AssessmentPage({
  params,
}: {
  params: Promise<{ id: string; assessmentId: string }>;
}) {
  const { id, assessmentId } = await params;

  return <AssessmentDetailView familyCode={id} assessmentId={assessmentId} />;
}
