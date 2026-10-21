import { StaffFollowupDetail } from "@/components/followups/staff-followups";

export default async function FollowupPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <StaffFollowupDetail id={id} />;
}
