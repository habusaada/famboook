import { CoordinatorRequestDetail } from "@/components/family/coordinator/requests/coordinator-request-detail";

export default async function CoordinatorRequestPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <CoordinatorRequestDetail id={id} />;
}
