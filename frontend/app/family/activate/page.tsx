import { ActivationFlow } from "@/components/family/activation/activation-flow";

// Public: outside the (portal) group, so no FamilyGate. One route for the
// whole flow — the steps are internal state, never a URL.

export default function FamilyActivatePage() {
  return <ActivationFlow />;
}
