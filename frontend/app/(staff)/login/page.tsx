import { Suspense } from "react";
import { LoginView } from "@/components/auth/login-view";

export default function LoginPage() {
  // useSearchParams (redirect target) needs a Suspense boundary.
  return (
    <Suspense fallback={null}>
      <LoginView />
    </Suspense>
  );
}
