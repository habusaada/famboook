import { AssessmentEditor } from "@/components/assessments/assessment-editor";

export default async function EditAssessmentPage({
  params,
}: {
  params: Promise<{ id: string; assessmentId: string }>;
}) {
  const { id, assessmentId } = await params;

  return <AssessmentEditor familyCode={id} assessmentId={assessmentId} />;
}
