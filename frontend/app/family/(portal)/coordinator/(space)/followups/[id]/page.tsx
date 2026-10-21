import { CoordinatorFollowupDetail } from "@/components/family/coordinator/followups/coordinator-followups";

export default async function CoordinatorFollowupPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <CoordinatorFollowupDetail id={id} />;
}
