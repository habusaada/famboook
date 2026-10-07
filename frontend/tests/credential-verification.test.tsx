import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { CredentialVerification } from "@/components/verify/credential-verification";
import { ApiError, apiClient } from "@/lib/api/client";
import type { VerifiedFamilyCard } from "@/lib/api/credentials";
import { FAMILY_SW_SCOPE } from "@/lib/pwa/family-service-worker";
import { formatDateLong } from "@/lib/utils/date";
import { browserStorageDump } from "./helpers";

// PWA-8.2: the public /verify/{token} page. The browser posts the token in
// the BODY; the approved fields only; one generic failure. Synthetic data.

const TOKEN = "Abc123_-".repeat(5) + "xyz";

function card(overrides: Partial<VerifiedFamilyCard> = {}): VerifiedFamilyCard {
  return {
    type: "FAMILY",
    credential_number: "FC-7K4P-9XMQ-2R",
    family_code: "FAM-000123",
    issued_at: "2026-10-16",
    clan: "عشيرة الاختبار",
    branch: "فرع الاختبار",
    head_name: "سالم الاختبار",
    ...overrides,
  };
}

const field = (name: string) => document.querySelector(`[data-field="${name}"] dd`);

beforeEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("the public verification page", () => {
  it("posts the token in the body to /api/v1/credentials/verify — never in a URL", async () => {
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({ data: card() } as never);
    const get = vi.spyOn(apiClient, "get");

    render(<CredentialVerification token={TOKEN} />);
    await screen.findByText("بطاقة صالحة");

    expect(post).toHaveBeenCalledTimes(1);
    expect(post).toHaveBeenCalledWith("/api/v1/credentials/verify", { token: TOKEN });
    expect(String(post.mock.calls[0][0])).not.toContain(TOKEN);
    expect(get).not.toHaveBeenCalled();
  });

  it("shows exactly the approved fields of a valid card, with the disclaimer", async () => {
    vi.spyOn(apiClient, "post").mockResolvedValue({ data: card() } as never);

    render(<CredentialVerification token={TOKEN} />);

    expect(await screen.findByRole("heading", { name: "بطاقة صالحة" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { level: 1, name: "التحقق من بطاقة الأسرة الرقمية" })).toBeInTheDocument();
    expect(field("credential_number")).toHaveTextContent("FC-7K4P-9XMQ-2R");
    expect(field("family_code")).toHaveTextContent("FAM-000123");
    expect(field("issued_at")).toHaveTextContent(formatDateLong("2026-10-16"));
    expect(field("clan")).toHaveTextContent("عشيرة الاختبار");
    expect(field("branch")).toHaveTextContent("فرع الاختبار");
    expect(field("head_name")).toHaveTextContent("سالم الاختبار");
    expect(screen.getByText(/وسيلة تحقق رقمية ضمن نظام Famboook، وليست وثيقة هوية رسمية/)).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent("هوية رسمية للأسرة");
    expect(document.querySelectorAll("[data-field]")).toHaveLength(6);
  });

  it("omits the head, clan and branch when the server sends none", async () => {
    vi.spyOn(apiClient, "post").mockResolvedValue({ data: card({ head_name: null, clan: null, branch: null }) } as never);

    render(<CredentialVerification token={TOKEN} />);
    await screen.findByText("بطاقة صالحة");

    expect(screen.queryByText("رب الأسرة الحالي")).not.toBeInTheDocument();
    expect(screen.queryByText("العشيرة")).not.toBeInTheDocument();
    expect(screen.queryByText("الفرع")).not.toBeInTheDocument();
  });

  it("shows only the one generic failure, never a reason", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(new ApiError(404, { message: "x", code: "CREDENTIAL_NOT_VERIFIABLE" }));

    render(<CredentialVerification token="not-a-token" />);

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر التحقق من هذه البطاقة.");
    for (const reason of ["قد يكون", "غير صحيح", "لم تعد سارية", "ملغاة", "منتهية", "غير نشطة"]) {
      expect(document.body).not.toHaveTextContent(reason);
    }
    expect(screen.queryByText("بطاقة صالحة")).not.toBeInTheDocument();
    expect(screen.getByText(/وسيلة تحقق رقمية/)).toBeInTheDocument();
  });

  it("shows the rate-limit message on 429 and the generic failure on a network error", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValueOnce(new ApiError(429, { message: "x" }));
    const { unmount } = render(<CredentialVerification token={TOKEN} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر التحقق الآن. يُرجى المحاولة لاحقًا.");
    unmount();

    vi.spyOn(apiClient, "post").mockRejectedValueOnce(new TypeError("Failed to fetch"));
    render(<CredentialVerification token={TOKEN} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر التحقق من هذه البطاقة.");
  });

  it("renders no portal navigation or login link, stores nothing, and lives outside the Family service worker scope", async () => {
    vi.spyOn(apiClient, "post").mockResolvedValue({ data: card() } as never);

    render(<CredentialVerification token={TOKEN} />);
    await screen.findByText("بطاقة صالحة");

    expect(screen.queryAllByRole("link")).toHaveLength(0);
    expect(screen.queryByRole("navigation")).not.toBeInTheDocument();
    expect(browserStorageDump()).not.toContain(TOKEN);
    expect(browserStorageDump()).not.toContain("FC-7K4P");
    expect("/verify/x".startsWith(FAMILY_SW_SCOPE)).toBe(false);
  });
});
