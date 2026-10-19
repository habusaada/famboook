import { StaffAnnouncementDetail } from "@/components/announcements/staff-announcement-detail";

export default async function AnnouncementPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <StaffAnnouncementDetail id={id} />;
}
