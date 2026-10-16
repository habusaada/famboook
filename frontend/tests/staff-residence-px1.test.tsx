import { screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { AuthContext, authValue } from "@/components/auth/auth-context";
import { FamilyResidenceTab } from "@/components/families/family-residence-tab";
import { apiClient } from "@/lib/api/client";
import type { CurrentUser } from "@/lib/api/auth";
import { useFamily } from "@/lib/api/families";
import type { FamilyDetail } from "@/lib/types/api/family";
import { renderWithClient } from "./helpers";

// FB-GEO-01 PX-1 (found in Stage 4C): a Staff residence correction dialog
// reopened before the family refetch landed resent the PREVIOUS text,
// silently overwriting the correction just saved. The page here is the real
// one: the residence tab fed by the real family query. Synthetic data.

beforeAll(() => {
  Object.assign(Element.prototype, {
    hasPointerCapture: () => false,
    releasePointerCapture: () => undefined,
    scrollIntoView: () => undefined,
  });
});

beforeEach(() => {
  vi.restoreAllMocks();
});

const CODE = "FAM-000123";
const FAMILY_PATH = `/api/v1/families/${CODE}`;

function family(addressText: string): { data: FamilyDetail } {
  return {
    data: {
      family_code: CODE,
      residence: {
        governorate: "نص محافظة", city: "نص مدينة", area: null, neighborhood: null, address_text: addressText,
        residence_type: null, started_at: null, original_residence_text: "نص أصلي", displacement_status: "NOT_DISPLACED",
        displacement_location_text: null, original_location_id: null, current_location_id: null, original_location: null, current_location: null,
      },
    } as FamilyDetail,
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

function Page() {
  const query = useFamily(CODE);
  if (!query.data) return null;
  return <FamilyResidenceTab family={query.data.data} />;
}

const staff: CurrentUser = { name: "موظف تجريبي", email: "staff@example.test", role: "DATA_ENTRY", role_label: "مدخل بيانات", permissions: ["residence.update"] };

function setup() {
  // The first family read answers at once; every later one waits for the test.
  let reads = 0;
  const refetches: ReturnType<typeof deferred<unknown>>[] = [];
  vi.spyOn(apiClient, "get").mockImplementation(async (path: string) => {
    if (path.startsWith("/api/v1/gaza-locations")) return { data: [] } as never;
    if (path !== FAMILY_PATH) throw new Error(`Unexpected GET ${path}`);
    reads += 1;
    if (reads === 1) return family("العنوان القديم") as never;
    const next = deferred<unknown>();
    refetches.push(next);
    return next.promise as never;
  });
  const patch = vi.spyOn(apiClient, "patch").mockImplementation(async (_path: string, body: unknown) => {
    return family((body as { address_text: string }).address_text) as never;
  });
  const user = userEvent.setup();
  const { client } = renderWithClient(
    <AuthContext.Provider value={authValue(staff)}>
      <Page />
    </AuthContext.Provider>
  );
  return { user, patch, refetches, client };
}

const editCurrent = async () => (await screen.findAllByRole("button", { name: "تعديل" })).at(-1)!;
const address = () => screen.getByLabelText(/العنوان التفصيلي/, { selector: "textarea" });
const save = () => within(screen.getByRole("dialog")).getByRole("button", { name: "حفظ التعديلات" });

describe("PX-1: residence correction never submits stale text", { timeout: 20_000 }, () => {
  it("a dialog reopened before the refetch lands starts from the saved text and cannot save until it lands", async () => {
    const { user, patch, refetches } = setup();

    await user.click(await editCurrent());
    await user.clear(address());
    await user.type(address(), "العنوان المصحّح");
    await user.click(save());
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(patch).toHaveBeenCalledTimes(1);

    // Reopen at once: the refetch is still in flight.
    await waitFor(() => expect(refetches.length).toBeGreaterThan(0));
    await user.click(await editCurrent());
    expect(address()).toHaveValue("العنوان المصحّح");
    expect(save()).toBeDisabled();
    expect(screen.getByText(/جارٍ تحديث بيانات السكن/)).toBeInTheDocument();

    // The refetch lands with the authoritative state: saving works again.
    refetches.forEach((r) => r.resolve(family("العنوان المصحّح")));
    await waitFor(() => expect(save()).toBeEnabled());

    await user.clear(screen.getByLabelText(/^المدينة/, { selector: "input" }));
    await user.type(screen.getByLabelText(/^المدينة/, { selector: "input" }), "مدينة مصحّحة");
    await user.click(save());
    await waitFor(() => expect(patch).toHaveBeenCalledTimes(2));
    const second = patch.mock.calls[1][1] as Record<string, unknown>;
    expect(second.address_text).toBe("العنوان المصحّح");
    expect(second.city).toBe("مدينة مصحّحة");
  });

  it("an open dialog adopts newer residence data instead of keeping a stale baseline", async () => {
    const { user, refetches, client } = setup();

    await user.click(await editCurrent());
    expect(address()).toHaveValue("العنوان القديم");

    // The residence changed elsewhere; a refetch brings it while the dialog is open.
    void client.invalidateQueries({ queryKey: ["families", CODE] });
    await waitFor(() => expect(refetches.length).toBe(1));
    expect(save()).toBeDisabled();
    refetches[0].resolve(family("عنوان من تصحيح آخر"));

    await waitFor(() => expect(address()).toHaveValue("عنوان من تصحيح آخر"));
    expect(save()).toBeEnabled();
  });
});
