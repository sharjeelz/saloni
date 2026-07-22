"use client";

import { createContext, useCallback, useContext, useState } from "react";
import { ConfirmDialog } from "./ConfirmDialog";

type Options = {
  title: string;
  message: string;
  confirmLabel: string;
  cancelLabel?: string;
  variant?: "danger" | "primary";
};
type Pending = Options & { resolve: (ok: boolean) => void };

const ConfirmContext = createContext<((opts: Options) => Promise<boolean>) | null>(null);

/**
 * Promise-based confirmation, so a handler can `if (!(await confirm({...}))) return;`
 * as a drop-in for the native confirm() — but styled, RTL, and localized.
 */
export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const [pending, setPending] = useState<Pending | null>(null);

  const confirm = useCallback(
    (opts: Options) => new Promise<boolean>((resolve) => setPending({ ...opts, resolve })),
    [],
  );

  const settle = (ok: boolean) => {
    pending?.resolve(ok);
    setPending(null);
  };

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <ConfirmDialog
        open={!!pending}
        title={pending?.title ?? ""}
        message={pending?.message ?? ""}
        confirmLabel={pending?.confirmLabel ?? ""}
        cancelLabel={pending?.cancelLabel}
        variant={pending?.variant ?? "danger"}
        onConfirm={() => settle(true)}
        onClose={() => settle(false)}
      />
    </ConfirmContext.Provider>
  );
}

export function useConfirm() {
  const ctx = useContext(ConfirmContext);
  if (!ctx) throw new Error("useConfirm must be used within a ConfirmProvider");
  return ctx;
}
