import { defineRouting } from "next-intl/routing";

export const locales = ["ar", "en"] as const;
export type Locale = (typeof locales)[number];

// Text direction per locale — drives <html dir> and RTL layout.
export const localeDirection: Record<Locale, "rtl" | "ltr"> = {
  ar: "rtl",
  en: "ltr",
};

export const routing = defineRouting({
  locales,
  // KSA-first: Arabic is the default.
  defaultLocale: "ar",
  localePrefix: "always",
});
