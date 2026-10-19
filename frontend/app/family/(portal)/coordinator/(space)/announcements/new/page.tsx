import { CoordinatorAnnouncementCompose } from "@/components/family/coordinator/announcements/coordinator-announcement-compose";

// Composing needs the CURRENT scope: this page is in the (space) group,
// behind CoordinatorGate. The backend re-checks the permission and the scope.
export default function CoordinatorNewAnnouncementPage() {
  return <CoordinatorAnnouncementCompose />;
}
