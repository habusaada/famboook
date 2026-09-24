"use client";

import { useRef, useState } from "react";
import type { UseMutationResult } from "@tanstack/react-query";
import type {
  FieldValues,
  Path,
  UseFormHandleSubmit,
  UseFormSetError,
} from "react-hook-form";
import { ApiError } from "@/lib/api/client";

/**
 * Save flow shared by the edit dialogs:
 * - one request at a time (a synchronous guard, because isPending only
 *   flips after async form validation — a fast double click could
 *   otherwise send two requests);
 * - Laravel 422 errors mapped onto form fields, with an Arabic summary;
 * - the dialog closes only on success, and cannot be closed while a
 *   request is in flight.
 */
export function useGuardedSave<TValues extends FieldValues, TPayload>({
  mutation,
  apiFieldToFormField,
  setError,
  statusMessages = {},
  onSuccess,
}: {
  mutation: UseMutationResult<unknown, Error, TPayload>;
  apiFieldToFormField: Record<string, Path<TValues>>;
  setError: UseFormSetError<TValues>;
  // Per-status Arabic messages, e.g. { 403: "لا تملك صلاحية ..." }.
  statusMessages?: Record<number, string>;
  // Optional follow-up after a successful save (e.g. navigation).
  onSuccess?: (data: unknown) => void;
}) {
  const [open, setOpenState] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const submitting = useRef(false);

  function save(payload: TPayload) {
    setSubmitError(null);

    mutation.mutate(payload, {
      onSettled: () => {
        submitting.current = false;
      },
      onSuccess: (data) => {
        setOpenState(false);
        onSuccess?.(data);
      },
      onError: (error) => {
        if (error instanceof ApiError && error.status === 422) {
          for (const [apiField, messages] of Object.entries(error.validationErrors ?? {})) {
            const formField = apiFieldToFormField[apiField];
            if (formField && messages[0]) {
              setError(formField, { type: "server", message: messages[0] });
            }
          }
          setSubmitError(error.message422 ?? "توجد أخطاء في البيانات المُدخلة.");
          return;
        }

        if (error instanceof ApiError && error.status === 401) {
          setSubmitError("انتهت جلسة الدخول. يرجى تسجيل الدخول مجددًا.");
          return;
        }

        if (error instanceof ApiError && statusMessages[error.status]) {
          setSubmitError(statusMessages[error.status]);
          return;
        }

        setSubmitError("تعذّر الاتصال بالخادم. الرجاء المحاولة مرة أخرى.");
      },
    });
  }

  return {
    open,
    setOpen: (next: boolean) => {
      // Not while a request is in flight: its result would land on a
      // dialog the user already dismissed.
      if (next || !mutation.isPending) setOpenState(next);
      if (next) setSubmitError(null);
    },
    submitError,
    isPending: mutation.isPending,
    submit(
      handleSubmit: UseFormHandleSubmit<TValues>,
      toPayload: (values: TValues) => TPayload
    ) {
      if (submitting.current) return;
      submitting.current = true;

      void handleSubmit(
        (values) => save(toPayload(values)),
        () => {
          // Client-side validation failed: no request was sent.
          submitting.current = false;
        }
      )();
    },
  };
}
