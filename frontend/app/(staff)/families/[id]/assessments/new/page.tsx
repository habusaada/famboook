import { AssessmentEditor } from "@/components/assessments/assessment-editor";

export default async function NewAssessmentPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <AssessmentEditor familyCode={id} />;
}
