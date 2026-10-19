import { FamilyNotificationDetail } from "@/components/family/notifications/family-notification-detail";

export default async function FamilyNotificationPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <FamilyNotificationDetail id={id} />;
}
