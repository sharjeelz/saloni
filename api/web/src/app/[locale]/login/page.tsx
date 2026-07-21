"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "@/i18n/navigation";
import { ApiError, post, setToken } from "@/lib/api";
import LocaleSwitcher from "@/components/LocaleSwitcher";

export default function LoginPage() {
  const t = useTranslations("app.login");
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<null | "credentials" | "connection">(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const res = await post<{ token: string }>("/auth/login", { email, password });
      setToken(res.token);
      router.replace("/dashboard");
    } catch (err) {
      // 422 = bad credentials; anything else (network/CORS/500) = server unreachable.
      setError(err instanceof ApiError && err.status === 422 ? "credentials" : "connection");
      setBusy(false);
    }
  }

  return (
    <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
      {/* Brand panel — the salon's thesis, botanical green + brass */}
      <aside className="relative hidden overflow-hidden bg-accent p-12 lg:flex lg:flex-col lg:justify-between">
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 opacity-[0.14]"
          style={{
            backgroundImage:
              "radial-gradient(circle at 80% 12%, var(--color-gold) 0, transparent 42%), radial-gradient(circle at 12% 88%, #fff 0, transparent 38%)",
          }}
        />
        <span className="relative font-mono text-xs uppercase tracking-[0.28em] text-white/70">
          KSA · SaaS
        </span>
        <div className="relative">
          <div className="mb-6 h-px w-16 bg-[color:var(--color-gold)]" />
          <h1 className="font-[family-name:var(--font-display)] text-5xl font-semibold leading-[1.15] text-white">
            {t("tagline")}
          </h1>
        </div>
        <span className="relative font-[family-name:var(--font-display)] text-2xl font-semibold text-white">
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
            <p className="font-mono text-xs uppercase tracking-[0.22em] text-gold">
              صالوني · Salooni
            </p>
            <h2 className="mt-3 font-[family-name:var(--font-display)] text-3xl font-semibold text-ink">
              {t("title")}
            </h2>
            <p className="mt-1.5 text-muted">{t("subtitle")}</p>

            <div className="mt-8 flex flex-col gap-4">
              <label className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-ink">{t("email")}</span>
                <input
                  type="email"
                  required
                  autoComplete="email"
                  dir="ltr"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  className="rounded-xl border border-line bg-surface px-4 py-3 text-ink outline-none transition-colors placeholder:text-muted/60 focus:border-accent"
                  placeholder="owner@salon.sa"
                />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-sm font-medium text-ink">{t("password")}</span>
                <input
                  type="password"
                  required
                  autoComplete="current-password"
                  dir="ltr"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="rounded-xl border border-line bg-surface px-4 py-3 text-ink outline-none transition-colors focus:border-accent"
                  placeholder="••••••••"
                />
              </label>

              {error && (
                <p className="rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">
                  {error === "credentials" ? t("error") : t("connError")}
                </p>
              )}

              <button
                type="submit"
                disabled={busy}
                className="mt-1 rounded-xl bg-accent px-4 py-3 font-medium text-white shadow-[var(--shadow)] transition-opacity hover:opacity-90 disabled:opacity-60"
              >
                {busy ? t("signingIn") : t("submit")}
              </button>
            </div>
          </form>
        </div>
      </main>
    </div>
  );
}
