import { CoordinatorAnnouncementDetail } from "@/components/family/coordinator/announcements/coordinator-announcement-detail";

export default async function CoordinatorAnnouncementPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <CoordinatorAnnouncementDetail id={id} />;
}
