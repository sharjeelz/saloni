"use client";

import { useTranslations } from "next-intl";

/* ---------- Page header ---------- */
export function PageHeader({
  title, children,
}: { title: string; children?: React.ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 className="font-[family-name:var(--font-display)] text-3xl font-semibold text-ink">
        {title}
      </h1>
      {children && <div className="flex items-center gap-2">{children}</div>}
    </div>
  );
}

/* ---------- Button ---------- */
type ButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: "primary" | "ghost" | "danger";
};
export function Button({ variant = "primary", className = "", ...props }: ButtonProps) {
  const styles = {
    primary: "bg-accent text-white hover:opacity-90 shadow-[var(--shadow)]",
    ghost: "border border-line text-ink hover:border-accent bg-surface",
    danger: "border border-line text-crit hover:border-crit bg-surface",
  }[variant];
  return (
    <button
      {...props}
      className={`inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-medium transition-colors disabled:opacity-60 ${styles} ${className}`}
    />
  );
}

/* ---------- Card ---------- */
export function Card({ className = "", children }: { className?: string; children: React.ReactNode }) {
  return (
    <div className={`rounded-2xl border border-line bg-surface shadow-[var(--shadow)] ${className}`}>
      {children}
    </div>
  );
}

/* ---------- Field (label + input) ---------- */
export function Field({
  label, children,
}: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-sm font-medium text-ink">{label}</span>
      {children}
    </label>
  );
}

const inputClass =
  "rounded-xl border border-line bg-surface px-3.5 py-2.5 text-ink outline-none transition-colors placeholder:text-muted/60 focus:border-accent w-full";

export function Input(props: React.InputHTMLAttributes<HTMLInputElement>) {
  return <input {...props} className={`${inputClass} ${props.className ?? ""}`} />;
}

export function Select(props: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return <select {...props} className={`${inputClass} ${props.className ?? ""}`} />;
}

/* ---------- Badge ---------- */
export function Badge({ tone = "muted", children }: { tone?: string; children: React.ReactNode }) {
  const map: Record<string, string> = {
    accent: "bg-accent-soft text-accent-ink",
    gold: "bg-gold-soft text-gold",
    ok: "text-ok",
    warn: "text-warn",
    crit: "text-crit",
    muted: "bg-surface-2 text-muted",
  };
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${map[tone] ?? map.muted}`}>
      {children}
    </span>
  );
}

/* ---------- Spinner ---------- */
export function Spinner() {
  return (
    <div className="grid min-h-[40vh] place-items-center">
      <div className="size-7 animate-spin rounded-full border-2 border-line border-t-accent" />
    </div>
  );
}

/* ---------- Load error / empty ---------- */
export function LoadError({ onRetry }: { onRetry: () => void }) {
  const t = useTranslations("app.common");
  return (
    <div className="grid min-h-[40vh] place-items-center text-center">
      <div>
        <p className="text-muted">{t("loadError")}</p>
        <Button variant="ghost" className="mt-3" onClick={onRetry}>{t("retry")}</Button>
      </div>
    </div>
  );
}

export function EmptyState({ message, action }: { message: string; action?: React.ReactNode }) {
  return (
    <div className="grid min-h-[36vh] place-items-center rounded-2xl border border-dashed border-line text-center">
      <div>
        <p className="text-muted">{message}</p>
        {action && <div className="mt-3 flex justify-center">{action}</div>}
      </div>
    </div>
  );
}
