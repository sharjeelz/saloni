"use client";

import { useTranslations } from "next-intl";
import { Modal } from "./Modal";
import { Button } from "./kit";

/**
 * Styled replacement for the native browser confirm() — consistent look, RTL,
 * and localized buttons. Render it conditionally on `open`; the caller keeps the
 * "what am I confirming" state and runs the action in onConfirm.
 */
export function ConfirmDialog({
  open,
  title,
  message,
  confirmLabel,
  cancelLabel,
  variant = "danger",
  busy = false,
  onConfirm,
  onClose,
}: {
  open: boolean;
  title: string;
  message: string;
  confirmLabel: string;
  cancelLabel?: string;
  variant?: "danger" | "primary";
  busy?: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  const c = useTranslations("app.common");
  if (!open) return null;

  return (
    <Modal open onClose={onClose} title={title}>
      <div className="flex flex-col gap-5">
        <p className="text-sm text-muted">{message}</p>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>{cancelLabel ?? c("cancel")}</Button>
          <Button type="button" variant={variant} disabled={busy} onClick={onConfirm}>{confirmLabel}</Button>
        </div>
      </div>
    </Modal>
  );
}
