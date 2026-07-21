"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { useAuth } from "@/lib/auth";
import LocaleSwitcher from "@/components/LocaleSwitcher";
import ThemeToggle from "./ThemeToggle";

type IconName = "dashboard" | "calendar" | "services" | "staff" | "branches" | "timeOff" | "billing" | "settings";

const ICONS: Record<IconName, React.ReactNode> = {
  dashboard: <path d="M3 13h8V3H3zM13 21h8V3h-8zM3 21h8v-6H3z" />,
  calendar: <><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M3 9h18M8 2v4M16 2v4" /></>,
  services: <><path d="M12 2l2.4 6.9H22l-6 4.3 2.3 7-6.3-4.4L5.7 20l2.3-7-6-4.3h7.6z" /></>,
  staff: <><circle cx="9" cy="8" r="3.2" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0M17 11a3 3 0 1 0-1-5.8M21.5 20a5.5 5.5 0 0 0-4-5.3" /></>,
  branches: <><path d="M3 21V8l9-5 9 5v13M9 21v-6h6v6" /></>,
  timeOff: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
  billing: <><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20" /></>,
  settings: <><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6" /></>,
};

const NAV: { href: string; key: string; icon: IconName }[] = [
  { href: "/dashboard", key: "dashboard", icon: "dashboard" },
  { href: "/calendar", key: "calendar", icon: "calendar" },
  { href: "/services", key: "services", icon: "services" },
  { href: "/staff", key: "staff", icon: "staff" },
  { href: "/branches", key: "branches", icon: "branches" },
  { href: "/time-off", key: "timeOff", icon: "timeOff" },
  { href: "/billing", key: "billing", icon: "billing" },
  { href: "/settings", key: "settings", icon: "settings" },
];

export default function Shell({ children }: { children: React.ReactNode }) {
  const t = useTranslations("app");
  const { user, salon, loading, logout } = useAuth();
  const router = useRouter();
  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !user) router.replace("/login");
  }, [loading, user, router]);

  if (loading || !user) {
    return (
      <div className="grid min-h-dvh place-items-center bg-ground">
        <div className="size-8 animate-spin rounded-full border-2 border-line border-t-accent" />
      </div>
    );
  }

  return (
    <div className="flex min-h-dvh bg-ground">
      {/* Sidebar — sits at the inline-start (right in RTL) */}
      <aside className="sticky top-0 hidden h-dvh w-60 shrink-0 flex-col border-e border-line bg-surface px-3 py-5 md:flex">
        <div className="flex items-center gap-2 px-3 pb-6">
          <span className="grid size-8 place-items-center rounded-lg bg-accent font-[family-name:var(--font-display)] text-lg font-bold text-white">
            ص
          </span>
          <span className="font-[family-name:var(--font-display)] text-lg font-semibold text-ink">
            {t("brand")}
          </span>
        </div>

        <nav className="flex flex-col gap-1">
          {NAV.map((item) => {
            const active = pathname === item.href;
            return (
              <Link
                key={item.key}
                href={item.href}
                className={`flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ${
                  active
                    ? "bg-accent-soft text-accent-ink"
                    : "text-muted hover:bg-surface-2 hover:text-ink"
                }`}
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                  {ICONS[item.icon]}
                </svg>
                {t(`nav.${item.key}`)}
              </Link>
            );
          })}
        </nav>

        <button
          type="button"
          onClick={() => { void logout().then(() => router.replace("/login")); }}
          className="mt-auto flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-muted transition-colors hover:bg-surface-2 hover:text-crit"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
          </svg>
          {t("nav.logout")}
        </button>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-10 flex items-center gap-3 border-b border-line bg-ground/80 px-6 py-3 backdrop-blur">
          <div className="min-w-0">
            <p className="truncate font-[family-name:var(--font-display)] text-lg font-semibold text-ink">
              {salon?.name}
            </p>
          </div>
          <div className="ms-auto flex items-center gap-2">
            <LocaleSwitcher />
            <ThemeToggle />
            <span className="grid size-9 place-items-center rounded-full bg-gold-soft font-semibold text-gold">
              {user.name.charAt(0)}
            </span>
          </div>
        </header>

        <main className="flex-1 px-6 py-7">{children}</main>
      </div>
    </div>
  );
}
