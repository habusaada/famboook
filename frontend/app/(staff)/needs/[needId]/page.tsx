import { NeedDetailView } from "@/components/needs/need-detail-view";

export default async function NeedPage({
  params,
}: {
  params: Promise<{ needId: string }>;
}) {
  const { needId } = await params;

  return <NeedDetailView needId={needId} />;
}
