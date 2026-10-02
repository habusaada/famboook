import { FamilyLoginForm } from "@/components/family/auth/login-form";

// Public: outside the (portal) group, so no FamilyGate and no /family/me.

export default function FamilyLoginPage() {
  return <FamilyLoginForm />;
}
