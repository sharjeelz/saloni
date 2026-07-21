"use client";

import { useLocale, useTranslations } from "next-intl";
import { useTransition } from "react";
import { usePathname, useRouter } from "@/i18n/navigation";
import { locales, type Locale } from "@/i18n/routing";

export default function LocaleSwitcher() {
  const t = useTranslations("common");
  const active = useLocale();
  const router = useRouter();
  const pathname = usePathname();
  const [isPending, startTransition] = useTransition();

  function switchTo(next: Locale) {
    if (next === active) return;
    startTransition(() => {
      // Keep the current route, swap only the locale segment.
      router.replace(pathname, { locale: next });
    });
  }

  return (
    <div
      className="inline-flex items-center gap-1 rounded-full border border-line p-1 text-sm"
      role="group"
      aria-label={t("language")}
      aria-busy={isPending}
    >
      {locales.map((loc) => (
        <button
          key={loc}
          type="button"
          onClick={() => switchTo(loc)}
          aria-pressed={loc === active}
          className={`rounded-full px-3 py-1 font-medium transition-colors ${
            loc === active
              ? "bg-accent text-on-accent"
              : "text-muted hover:text-ink"
          }`}
        >
          {loc === "ar" ? t("arabic") : t("english")}
        </button>
      ))}
    </div>
  );
}
