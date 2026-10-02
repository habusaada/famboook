import { PasswordResetFlow } from "@/components/family/auth/password-reset-flow";

// Public: outside the (portal) group. One route for the whole flow — the
// steps are internal state, never a URL.

export default function FamilyForgotPasswordPage() {
  return <PasswordResetFlow />;
}
