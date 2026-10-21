import { FamilyNotificationsGate } from "@/components/family/notifications/family-notifications-gate";

// Pilot isolation (ADR FB-NOTIFY-01 §2h): the inbox, archive and details open
// only when /family/me reports `notifications` — no request otherwise.

export default function FamilyNotificationsLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return <FamilyNotificationsGate>{children}</FamilyNotificationsGate>;
}
