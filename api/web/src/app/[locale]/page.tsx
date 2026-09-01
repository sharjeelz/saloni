import { useTranslations } from "next-intl";
import { setRequestLocale } from "next-intl/server";
import { use } from "react";
import { Link } from "@/i18n/navigation";
import LocaleSwitcher from "@/components/LocaleSwitcher";

const featureKeys = ["booking", "branches", "reminders"] as const;

export default function HomePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = use(params);
  setRequestLocale(locale);

  const t = useTranslations("home");
  const nav = useTranslations("nav");

  return (
    <div className="flex min-h-full flex-col">
      {/* Header — logical padding (ps/pe) keeps it correct in RTL & LTR */}
      <header className="flex items-center justify-between gap-4 px-6 py-4 sm:px-10">
        <span className="font-mono text-sm font-semibold tracking-tight">
          صالون · Salon
        </span>
        <div className="flex items-center gap-4">
          <nav className="hidden items-center gap-5 text-sm text-muted sm:flex">
            <a href="#features" className="hover:text-ink">
              {nav("features")}
            </a>
          </nav>
          <Link href="/login" className="text-sm font-medium text-ink hover:text-accent-ink">
            {nav("signIn")}
          </Link>
          <Link
            href="/signup"
            className="rounded-full bg-accent px-4 py-2 text-sm font-medium text-on-accent hover:opacity-90"
          >
            {nav("getStarted")}
          </Link>
          <LocaleSwitcher />
        </div>
      </header>

      {/* Hero */}
      <main className="flex flex-1 flex-col items-center justify-center gap-10 px-6 py-16 text-center sm:px-10">
        <div className="flex max-w-2xl flex-col items-center gap-5">
          <p className="font-mono text-xs uppercase tracking-widest text-muted">
            {t("kicker")}
          </p>
          <h1 className="text-balance text-4xl font-semibold leading-tight sm:text-5xl">
            {t("title")}
          </h1>
          <p className="text-pretty text-lg text-muted">{t("subtitle")}</p>
          <div className="mt-2 flex flex-wrap items-center justify-center gap-3">
            <Link
              href="/signup"
              className="rounded-full bg-accent px-5 py-2.5 text-sm font-medium text-on-accent hover:opacity-90"
            >
              {t("ctaPrimary")}
            </Link>
            <a
              href="#features"
              className="rounded-full border border-line px-5 py-2.5 text-sm font-medium hover:border-accent"
            >
              {t("ctaSecondary")}
            </a>
          </div>
          <p className="font-mono text-xs text-muted">{t("trial")}</p>
        </div>

        {/* Features */}
        <section
          id="features"
          className="grid w-full max-w-3xl gap-4 sm:grid-cols-3"
        >
          {featureKeys.map((key) => (
            <div
              key={key}
              className="rounded-2xl border border-line p-5 text-start"
            >
              <h3 className="mb-1.5 font-semibold">
                {t(`features.${key}.title`)}
              </h3>
              <p className="text-sm text-muted">
                {t(`features.${key}.body`)}
              </p>
            </div>
          ))}
        </section>
      </main>
    </div>
  );
}
