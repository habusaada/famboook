import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { FamilyCard } from "@/components/family/card/family-card";
import { FamilyGate } from "@/components/family/family-gate";
import { FamilyHome } from "@/components/family/family-home";
import { ApiError, apiClient } from "@/lib/api/client";
import type { FamilyCard as FamilyCardData } from "@/lib/api/family-card";
import { formatDateLong } from "@/lib/utils/date";
import { browserStorageDump, familyGet, renderWithClient } from "./helpers";

// PWA-8.2: /family/card «بطاقة الأسرة الرقمية» on POST /api/v1/family/card,
// and its entry on the Family home. Synthetic data only.

const router = { replace: vi.fn(), push: vi.fn() };
const location = { pathname: "/family/card" };
vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => location.pathname }));
vi.mock("next/link", () => ({
  default: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

const SVG = "data:image/svg+xml;base64," + btoa("<svg xmlns='http://www.w3.org/2000/svg'></svg>");

function card(overrides: Partial<FamilyCardData> = {}): FamilyCardData {
  return {
    credential_number: "FC-7K4P-9XMQ-2R",
    family_code: "FAM-000123",
    issued_at: "2026-10-16",
    clan: "عشيرة الاختبار",
    branch: "فرع الاختبار",
    head_name: "سالم أحمد محمد الاختبار",
    verification_url: "https://famboook.test/verify/token",
    qr: SVG,
    qr_available: true,
    ...overrides,
  };
}

function renderCard(answer: FamilyCardData | Error | (() => Promise<unknown>) = card()) {
  vi.spyOn(apiClient, "get").mockImplementation(familyGet());
  const post = vi.spyOn(apiClient, "post").mockImplementation(async (path: string) => {
    if (path !== "/api/v1/family/card") throw new Error(`Unexpected POST ${path}`);
    if (typeof answer === "function") return answer() as never;
    if (answer instanceof Error) throw answer;
    return { data: answer } as never;
  });
  const result = renderWithClient(
    <FamilyGate>
      <FamilyCard />
    </FamilyGate>
  );

  return { post, ...result };
}

const field = (name: string) => document.querySelector(`[data-field="${name}"] dd`);

beforeEach(() => {
  vi.restoreAllMocks();
  location.pathname = "/family/card";
  window.localStorage.clear();
  window.sessionStorage.clear();
});

describe("«بطاقة الأسرة الرقمية»", () => {
  it("ensures the card with one POST that sends no identifier", async () => {
    const { post } = renderCard();

    expect(await screen.findByRole("heading", { level: 1, name: "بطاقة الأسرة الرقمية" })).toBeInTheDocument();
    await screen.findByText("FC-7K4P-9XMQ-2R");
    expect(post).toHaveBeenCalledWith("/api/v1/family/card", {});
  });

  it("shows the card face: the current head's full name, card number, family code, clan, branch, issue date and the QR", async () => {
    renderCard();
    await screen.findByText("FC-7K4P-9XMQ-2R");

    expect(screen.getByRole("heading", { level: 2, name: "سالم أحمد محمد الاختبار" })).toBeInTheDocument();
    expect(field("credential_number")).toHaveTextContent("FC-7K4P-9XMQ-2R");
    expect(field("family_code")).toHaveTextContent("FAM-000123");
    expect(field("clan")).toHaveTextContent("عشيرة الاختبار");
    expect(field("branch")).toHaveTextContent("فرع الاختبار");
    expect(field("issued_at")).toHaveTextContent(formatDateLong("2026-10-16"));
    const qr = screen.getByRole("img", { name: "رمز QR للتحقق من بطاقة الأسرة الرقمية" });
    expect(qr).toHaveAttribute("src", SVG);
    expect(screen.getByText("اعرض رمز QR عند طلب التحقق من بطاقة أسرتك.")).toBeInTheDocument();
    expect(screen.getByText(/وسيلة تحقق رقمية ضمن نظام Famboook، وليست وثيقة هوية رسمية/)).toBeInTheDocument();
  });

  it("shows the card without a QR when the server cannot rebuild it", async () => {
    renderCard(card({ qr: null, qr_available: false, verification_url: null }));
    await screen.findByText("FC-7K4P-9XMQ-2R");

    expect(screen.queryByRole("img", { name: "رمز QR للتحقق من بطاقة الأسرة الرقمية" })).not.toBeInTheDocument();
    expect(screen.getByText("تعذّر عرض رمز التحقق حاليًا. يُرجى مراجعة إدارة السجل.")).toBeInTheDocument();
  });

  it("shows a loading state, then an error with retry", async () => {
    let fail = true;
    renderCard(() => (fail ? Promise.reject(new ApiError(500, { message: "x" })) : Promise.resolve({ data: card() })));

    expect(await screen.findByText("تعذّر تحميل البطاقة")).toBeInTheDocument();
    fail = false;
    await userEvent.click(screen.getByRole("button", { name: "إعادة المحاولة" }));
    expect(await screen.findByText("FC-7K4P-9XMQ-2R")).toBeInTheDocument();
  });

  it("says the card is unavailable while issuance is switched off and there is no card yet", async () => {
    renderCard(new ApiError(503, { message: "x", code: "ISSUANCE_DISABLED" }));

    expect(await screen.findByText("بطاقة الأسرة الرقمية غير متاحة حاليًا.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "إعادة المحاولة" })).not.toBeInTheDocument();
  });

  it("offers no print, copy or reissue control besides the PDF download, and stores nothing", async () => {
    renderCard();
    await screen.findByText("FC-7K4P-9XMQ-2R");

    const face = document.querySelector("[data-family-card]") as HTMLElement;
    expect(within(face).queryAllByRole("button")).toHaveLength(0);
    for (const text of [/طباعة/, /نسخ/, /إعادة إصدار/]) {
      expect(screen.queryByText(text)).not.toBeInTheDocument();
    }
    // Inside the page itself (the shell keeps its own logout / navigation).
    const page = document.querySelector("main") as HTMLElement;
    expect(within(page).getAllByRole("button").map((b) => b.textContent)).toEqual(["تنزيل البطاقة PDF"]);
    expect(browserStorageDump()).not.toMatch(/FC-7K4P|verify|svg/i);
  });
});

describe("«تنزيل البطاقة PDF»", () => {
  const PDF = "/api/v1/family/card/pdf";

  function stubDownload() {
    const create = vi.fn(() => "blob:famboook-test");
    const revoke = vi.fn();
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke });
    const click = vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
    return { create, revoke, click };
  }

  it("is offered only when the card has a QR", async () => {
    renderCard(card({ qr: null, qr_available: false, verification_url: null }));
    await screen.findByText("FC-7K4P-9XMQ-2R");

    expect(screen.queryByRole("button", { name: /تنزيل البطاقة PDF/ })).not.toBeInTheDocument();
  });

  it("downloads the PDF with one GET — no POST, no issuance — and revokes the temporary URL", async () => {
    const { post } = renderCard();
    await screen.findByText("FC-7K4P-9XMQ-2R");
    const getBlob = vi.spyOn(apiClient, "getBlob").mockResolvedValue(new Blob(["%PDF-1.4"], { type: "application/pdf" }));
    const { create, revoke, click } = stubDownload();

    await userEvent.click(screen.getByRole("button", { name: "تنزيل البطاقة PDF" }));

    await waitFor(() => expect(click).toHaveBeenCalledTimes(1));
    const anchor = click.mock.contexts[0] as HTMLAnchorElement;
    expect(getBlob).toHaveBeenCalledWith(PDF, "application/pdf");
    expect(post).toHaveBeenCalledTimes(1);
    expect(create).toHaveBeenCalledTimes(1);
    expect(anchor.download).toBe("famboook-family-card-FC-7K4P-9XMQ-2R.pdf");
    expect(anchor.href).toBe("blob:famboook-test");
    expect(document.querySelector('a[download]')).toBeNull();
    await waitFor(() => expect(revoke).toHaveBeenCalledWith("blob:famboook-test"), { timeout: 2500 });
    expect(browserStorageDump()).not.toMatch(/PDF|blob:/);
  });

  it("disables the button and shows a busy state while the file is prepared", async () => {
    renderCard();
    await screen.findByText("FC-7K4P-9XMQ-2R");
    let resolve: (blob: Blob) => void = () => {};
    vi.spyOn(apiClient, "getBlob").mockReturnValue(new Promise<Blob>((r) => (resolve = r)));
    stubDownload();

    await userEvent.click(screen.getByRole("button", { name: "تنزيل البطاقة PDF" }));

    const busy = await screen.findByRole("button", { name: /جارٍ تجهيز الملف/ });
    expect(busy).toBeDisabled();
    expect(busy).toHaveAttribute("aria-busy", "true");
    resolve(new Blob(["%PDF"]));
    expect(await screen.findByRole("button", { name: "تنزيل البطاقة PDF" })).toBeEnabled();
  });

  it("shows an inline failure and keeps the card", async () => {
    renderCard();
    await screen.findByText("FC-7K4P-9XMQ-2R");
    vi.spyOn(apiClient, "getBlob").mockRejectedValue(new ApiError(500, { message: "x" }));
    stubDownload();

    await userEvent.click(screen.getByRole("button", { name: "تنزيل البطاقة PDF" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر تنزيل البطاقة. حاول مرة أخرى.");
    expect(screen.getByText("FC-7K4P-9XMQ-2R")).toBeInTheDocument();
  });

  it("reloads the card after a 404 CARD_NOT_ISSUED and explains it", async () => {
    const { post } = renderCard();
    await screen.findByText("FC-7K4P-9XMQ-2R");
    vi.spyOn(apiClient, "getBlob").mockRejectedValue(new ApiError(404, { message: "x", code: "CARD_NOT_ISSUED" }));
    stubDownload();

    await userEvent.click(screen.getByRole("button", { name: "تنزيل البطاقة PDF" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("تغيّرت حالة البطاقة");
    await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
  });

  it("explains a 409 CARD_QR_UNAVAILABLE", async () => {
    renderCard();
    await screen.findByText("FC-7K4P-9XMQ-2R");
    vi.spyOn(apiClient, "getBlob").mockRejectedValue(new ApiError(409, { message: "x", code: "CARD_QR_UNAVAILABLE" }));
    stubDownload();

    await userEvent.click(screen.getByRole("button", { name: "تنزيل البطاقة PDF" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("تعذّر إنشاء رمز التحقق حاليًا");
  });
});

describe("the home entry", () => {
  it("links the Family home to /family/card", async () => {
    location.pathname = "/family";
    vi.spyOn(apiClient, "get").mockImplementation(familyGet());
    renderWithClient(
      <FamilyGate>
        <FamilyHome />
      </FamilyGate>
    );

    const entry = await screen.findByRole("link", { name: /بطاقة الأسرة الرقمية/ });
    expect(entry).toHaveAttribute("href", "/family/card");
  });
});
