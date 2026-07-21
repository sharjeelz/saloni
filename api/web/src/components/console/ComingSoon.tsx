import { useTranslations } from "next-intl";

export default function ComingSoon({ titleKey }: { titleKey: string }) {
  const t = useTranslations("app.nav");
  return (
    <div className="mx-auto max-w-5xl">
      <h1 className="font-[family-name:var(--font-display)] text-3xl font-semibold text-ink">
        {t(titleKey)}
      </h1>
      <div className="mt-6 grid min-h-[40vh] place-items-center rounded-2xl border border-dashed border-line bg-surface/50">
        <div className="text-center">
          <span className="grid size-12 place-items-center rounded-full bg-accent-soft text-accent-ink mx-auto">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round">
              <path d="M12 6v6l4 2" /><circle cx="12" cy="12" r="9" />
            </svg>
          </span>
          <p className="mt-3 text-muted">This screen is on the way.</p>
        </div>
      </div>
    </div>
  );
}
