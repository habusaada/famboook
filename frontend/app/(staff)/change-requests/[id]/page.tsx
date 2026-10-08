import { ChangeRequestDetailView } from "@/components/change-requests/change-request-detail-view";

export default async function ChangeRequestPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <ChangeRequestDetailView id={id} />;
}
