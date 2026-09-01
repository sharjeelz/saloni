"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Link, useRouter } from "@/i18n/navigation";
import { ApiError, get, getToken, post, setToken } from "@/lib/api";
import LocaleSwitcher from "@/components/LocaleSwitcher";

/** Fields the API validates — used to map its 422 response back onto inputs. */
type Field = "salon_name" | "owner_name" | "email" | "phone" | "password";

const FIELDS: Field[] = ["salon_name", "owner_name", "email", "phone", "password"];

/** Laravel 422 shape: { message, errors: { field: [msg, ...] } } */
function fieldErrors(err: unknown): Partial<Record<Field, string>> {
  if (!(err instanceof ApiError) || err.status !== 422) return {};
  const errors = (err.body as { errors?: Record<string, string[]> })?.errors ?? {};
  const out: Partial<Record<Field, string>> = {};
  for (const field of FIELDS) {
    const first = errors[field]?.[0];
    if (first) out[field] = first;
  }
  return out;
}

export default function SignupPage() {
  const t = useTranslations("app.signup");
  const router = useRouter();

  const [form, setForm] = useState<Record<Field, string>>({
    salon_name: "",
    owner_name: "",
    email: "",
    phone: "",
    password: "",
  });
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [failed, setFailed] = useState<null | "connection">(null);
  const [busy, setBusy] = useState(false);
  const [checking, setChecking] = useState(true);

  // Already signed in? Verify the token, then skip the form — same guard as login.
  useEffect(() => {
    if (!getToken()) {
      setChecking(false);
      return;
    }
    let live = true;
    get("/auth/me")
      .then(() => live && router.replace("/dashboard"))
      .catch(() => live && setChecking(false)); // invalid token cleared by api()
    return () => {
      live = false;
    };
  }, [router]);

  function update(field: Field, value: string) {
    setForm((f) => ({ ...f, [field]: value }));
    // Clear this field's server error as soon as the user edits it.
    setErrors((e) => (e[field] ? { ...e, [field]: undefined } : e));
  }

  if (checking) {
    return (
      <div className="grid min-h-dvh place-items-center bg-ground">
        <div className="size-8 animate-spin rounded-full border-2 border-line border-t-accent" />
      </div>
    );
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setErrors({});
    setFailed(null);
    setBusy(true);
    try {
      // Creates the salon + owner and starts the 14-day trial in one transaction.
      const res = await post<{ token: string }>("/auth/signup", form);
      setToken(res.token);
      router.replace("/dashboard");
    } catch (err) {
      const perField = fieldErrors(err);
      if (Object.keys(perField).length > 0) {
        setErrors(perField);
      } else {
        // Non-422 (network / CORS / 500) — nothing field-specific to show.
        setFailed("connection");
      }
      setBusy(false);
    }
  }

  const inputClass =
    "rounded-xl border border-line bg-surface px-4 py-3 text-ink outline-none transition-colors placeholder:text-muted/60 focus:border-accent";

  return (
    <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
      {/* Brand panel — mirrors the login page so the pair reads as one system. */}
      <aside className="relative hidden overflow-hidden bg-accent p-12 lg:flex lg:flex-col lg:justify-between">
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 opacity-[0.14]"
          style={{
            backgroundImage:
              "radial-gradient(circle at 80% 12%, var(--color-gold) 0, transparent 42%), radial-gradient(circle at 12% 88%, #fff 0, transparent 38%)",
          }}
        />
        <span className="relative font-mono text-xs uppercase tracking-[0.28em] text-on-accent/70">
          {t("eyebrow")}
        </span>
        <div className="relative">
          <div className="mb-6 h-px w-16 bg-[color:var(--color-gold)]" />
          <h1 className="font-[family-name:var(--font-display)] text-5xl font-semibold leading-[1.15] text-on-accent">
            {t("tagline")}
          </h1>
          <p className="mt-5 max-w-sm text-on-accent/75">{t("trialNote")}</p>
        </div>
        <span className="relative font-[family-name:var(--font-display)] text-2xl font-semibold text-on-accent">
          صالوني
        </span>
      </aside>

      {/* Form */}
      <main className="flex flex-col bg-ground">
        <div className="flex justify-end p-5">
          <LocaleSwitcher />
        </div>
        <div className="flex flex-1 items-center justify-center px-6 pb-16">
          <form onSubmit={submit} className="w-full max-w-sm">
            {/* Compact brand moment on small screens (the panel is lg-only). */}
            <div className="mb-6 lg:hidden">
              <span className="inline-grid size-11 place-items-center rounded-xl bg-accent font-[family-name:var(--font-display)] text-xl font-bold text-on-accent">
                ص
              </span>
              <p className="mt-3 text-pretty font-[family-name:var(--font-display)] text-xl font-semibold text-ink">
                {t("tagline")}
              </p>
            </div>
            <p className="font-mono text-xs uppercase tracking-[0.22em] text-gold">
              صالوني · Salooni
            </p>
            <h2 className="mt-3 font-[family-name:var(--font-display)] text-3xl font-semibold text-ink">
              {t("title")}
            </h2>
            <p className="mt-1.5 text-muted">{t("subtitle")}</p>

            <div className="mt-8 flex flex-col gap-4">
              <label className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-ink">{t("salonName")}</span>
                <input
                  required
                  minLength={2}
                  maxLength={255}
                  autoComplete="organization"
                  value={form.salon_name}
                  onChange={(e) => update("salon_name", e.target.value)}
                  className={inputClass}
                  placeholder={t("salonNamePlaceholder")}
                />
                {errors.salon_name && (
                  <span className="text-sm text-crit">{errors.salon_name}</span>
                )}
              </label>

              <label className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-ink">{t("ownerName")}</span>
                <input
                  required
                  minLength={2}
                  maxLength={255}
                  autoComplete="name"
                  value={form.owner_name}
                  onChange={(e) => update("owner_name", e.target.value)}
                  className={inputClass}
                  placeholder={t("ownerNamePlaceholder")}
                />
                {errors.owner_name && (
                  <span className="text-sm text-crit">{errors.owner_name}</span>
                )}
              </label>

              <label className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-ink">{t("email")}</span>
                <input
                  type="email"
                  required
                  autoComplete="email"
                  dir="ltr"
                  value={form.email}
                  onChange={(e) => update("email", e.target.value)}
                  className={inputClass}
                  placeholder="owner@salon.sa"
                />
                {errors.email && <span className="text-sm text-crit">{errors.email}</span>}
              </label>

              <label className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-ink">{t("phone")}</span>
                <input
                  type="tel"
                  required
                  autoComplete="tel"
                  dir="ltr"
                  // Mirrors the API rule: optional +, then 9-15 digits.
                  pattern="\+?[0-9]{9,15}"
                  value={form.phone}
                  onChange={(e) => update("phone", e.target.value)}
                  className={inputClass}
                  placeholder="+9665XXXXXXXX"
                />
                <span className="text-xs text-muted">{t("phoneHint")}</span>
                {errors.phone && <span className="text-sm text-crit">{errors.phone}</span>}
              </label>

              <label className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-ink">{t("password")}</span>
                <input
                  type="password"
                  required
                  minLength={8}
                  autoComplete="new-password"
                  dir="ltr"
                  value={form.password}
                  onChange={(e) => update("password", e.target.value)}
                  className={inputClass}
                  placeholder="••••••••"
                />
                <span className="text-xs text-muted">{t("passwordHint")}</span>
                {errors.password && (
                  <span className="text-sm text-crit">{errors.password}</span>
                )}
              </label>

              {failed && (
                <p className="rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">
                  {t("connError")}
                </p>
              )}

              <button
                type="submit"
                disabled={busy}
                className="mt-1 rounded-xl bg-accent px-4 py-3 font-medium text-on-accent shadow-[var(--shadow)] transition-opacity hover:opacity-90 disabled:opacity-60"
              >
                {busy ? t("creating") : t("submit")}
              </button>

              <p className="text-center text-sm text-muted">
                {t("haveAccount")}{" "}
                <Link href="/login" className="font-medium text-accent hover:underline">
                  {t("signIn")}
                </Link>
              </p>
            </div>
          </form>
        </div>
      </main>
    </div>
  );
}
