"use client";

import { useEffect, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { get } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Dashboard = {
  range: { from: string; to: string; timezone: string };
  totals: { bookings: number; by_status: Record<string, number> };
  revenue: { collected: number; expected: number; currency: string };
  by_staff: { staff_id: number; staff: string; bookings: number; revenue: number }[];
  by_service: { service_id: number; service: string; bookings: number; revenue: number }[];
  upcoming: {
    id: number;
    starts_at: string;
    customer: { name: string } | null;
    service: { name: string } | null;
    staff: { name: string } | null;
  }[];
};

const STATUS_META: Record<string, { key: string; color: string }> = {
  confirmed: { key: "statusConfirmed", color: "var(--color-accent)" },
  pending: { key: "statusPending", color: "var(--color-gold)" },
  done: { key: "statusDone", color: "var(--color-ok)" },
  no_show: { key: "statusNoShow", color: "var(--color-warn)" },
  cancelled: { key: "statusCancelled", color: "var(--color-muted)" },
};

export default function DashboardPage() {
  const t = useTranslations("app.dashboard");
  const locale = useLocale();
  const { user } = useAuth();
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState(false);
  const [loading, setLoading] = useState(true);
  const [range, setRange] = useState<"today" | "week" | "month">("today");

  useEffect(() => {
    const iso = (d: Date) =>
      `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
    const to = new Date();
    const from = new Date();
    if (range === "week") from.setDate(from.getDate() - 6);
    if (range === "month") from.setDate(from.getDate() - 29);

    let live = true;
    setLoading(true);
    setError(false);
    get<Dashboard>(`/dashboard?from=${iso(from)}&to=${iso(to)}`)
      .then((d) => live && setData(d))
      .catch(() => live && setError(true))
      .finally(() => live && setLoading(false));
    return () => { live = false; };
  }, [range]);

  const money = (n: number, currency: string) =>
    new Intl.NumberFormat(locale, { style: "currency", currency, maximumFractionDigits: 2 }).format(n);

  const time = (iso: string, tz: string) =>
    new Intl.DateTimeFormat(locale, { timeZone: tz, hour: "2-digit", minute: "2-digit" }).format(new Date(iso));
  const day = (iso: string, tz: string) =>
    new Intl.DateTimeFormat(locale, { timeZone: tz, weekday: "short", day: "numeric", month: "short" }).format(new Date(iso));

  const header = (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <p className="font-mono text-xs uppercase tracking-[0.2em] text-gold">
          {t("greeting", { name: user?.name ?? "" })}
        </p>
        <h1 className="mt-1 font-[family-name:var(--font-display)] text-3xl font-semibold text-ink">
          {t("title")}
        </h1>
      </div>
      <div className="flex gap-1.5">
        {(["today", "week", "month"] as const).map((r) => (
          <button
            key={r}
            onClick={() => setRange(r)}
            className={`rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${
              range === r ? "bg-accent text-on-accent" : "border border-line text-muted hover:text-ink"
            }`}
          >
            {t(`range${r.charAt(0).toUpperCase()}${r.slice(1)}`)}
          </button>
        ))}
      </div>
    </div>
  );

  if (loading) return <div className="mx-auto max-w-5xl">{header}<DashboardSkeleton /></div>;

  if (error || !data) {
    return (
      <div className="mx-auto max-w-5xl">
        {header}
        <div className="grid min-h-[40vh] place-items-center text-center">
          <div>
            <p className="text-muted">{t("loadError")}</p>
            <button
              onClick={() => location.reload()}
              className="mt-3 rounded-full border border-line px-4 py-2 text-sm font-medium text-ink hover:border-accent"
            >
              {t("retry")}
            </button>
          </div>
        </div>
      </div>
    );
  }

  const statuses = Object.entries(STATUS_META)
    .map(([k, m]) => ({ ...m, count: data.totals.by_status[k] ?? 0 }))
    .filter((s) => s.count > 0);
  const statusTotal = statuses.reduce((s, x) => s + x.count, 0) || 1;
  const maxStaff = Math.max(1, ...data.by_staff.map((s) => s.bookings));
  const maxService = Math.max(1, ...data.by_service.map((s) => s.bookings));

  return (
    <div className="mx-auto max-w-5xl">
      {header}

      {/* Signature hero — collected revenue in brass, day status stripe */}
      <section className="mt-6 grid gap-4 md:grid-cols-[1.3fr_1fr]">
        <div className="rounded-2xl border border-line bg-surface p-6 shadow-[var(--shadow)]">
          <p className="text-sm text-muted">{t("revenueCollected")}</p>
          <p className="mt-1 font-[family-name:var(--font-display)] text-5xl font-semibold text-gold tnum">
            {money(data.revenue.collected, data.revenue.currency)}
          </p>
          <p className="mt-2 text-sm text-muted">
            {t("revenueExpected")}:{" "}
            <span className="font-medium text-ink tnum">
              {money(data.revenue.expected, data.revenue.currency)}
            </span>
          </p>
        </div>

        <div className="rounded-2xl border border-line bg-surface p-6 shadow-[var(--shadow)]">
          <div className="flex items-baseline justify-between">
            <p className="text-sm text-muted">{t("bookings")}</p>
            <p className="font-[family-name:var(--font-display)] text-3xl font-semibold text-ink tnum">
              {data.totals.bookings}
            </p>
          </div>
          {statuses.length > 0 ? (
            <>
              <div className="mt-4 flex h-2.5 overflow-hidden rounded-full bg-surface-2">
                {statuses.map((s) => (
                  <span key={s.key} style={{ width: `${(s.count / statusTotal) * 100}%`, background: s.color }} />
                ))}
              </div>
              <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                {statuses.map((s) => (
                  <li key={s.key} className="flex items-center gap-1.5 text-xs text-muted">
                    <span className="size-2 rounded-full" style={{ background: s.color }} />
                    {t(s.key)} <span className="font-medium text-ink tnum">{s.count}</span>
                  </li>
                ))}
              </ul>
            </>
          ) : (
            <p className="mt-4 text-sm text-muted">{t("empty")}</p>
          )}
        </div>
      </section>

      {/* Breakdowns */}
      <section className="mt-4 grid gap-4 md:grid-cols-2">
        <RankCard title={t("byStaff")} unit={t("bookingsCol")}
          rows={data.by_staff.map((s) => ({ label: s.staff, value: s.bookings }))} max={maxStaff} />
        <RankCard title={t("byService")} unit={t("bookingsCol")}
          rows={data.by_service.map((s) => ({ label: s.service, value: s.bookings }))} max={maxService} />
      </section>

      {/* Upcoming */}
      <section className="mt-4 rounded-2xl border border-line bg-surface p-6 shadow-[var(--shadow)]">
        <h2 className="font-[family-name:var(--font-display)] text-lg font-semibold text-ink">
          {t("nextUp")}
        </h2>
        {data.upcoming.length === 0 ? (
          <p className="mt-3 text-sm text-muted">{t("emptyUpcoming")}</p>
        ) : (
          <div className="mt-3 overflow-x-auto">
            <table className="w-full border-collapse text-start text-sm">
              <thead>
                <tr className="border-b border-line">
                  {[t("upDate"), t("upTime"), t("upCustomer"), t("upService")].map((h) => (
                    <th key={h} className="py-2 pe-4 text-start text-xs font-medium uppercase tracking-wide text-muted">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.upcoming.map((a) => (
                  <tr key={a.id} className="border-b border-line last:border-0">
                    <td className="whitespace-nowrap py-2.5 pe-4 text-muted">{day(a.starts_at, data.range.timezone)}</td>
                    <td className="whitespace-nowrap py-2.5 pe-4 font-mono text-accent-ink tnum" dir="ltr">
                      {time(a.starts_at, data.range.timezone)}
                    </td>
                    <td className="py-2.5 pe-4 font-medium text-ink">{a.customer?.name}</td>
                    <td className="py-2.5 text-muted">
                      {a.service?.name} · {t("with")} {a.staff?.name}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

function RankCard({
  title, unit, rows, max,
}: { title: string; unit: string; rows: { label: string; value: number }[]; max: number }) {
  return (
    <div className="rounded-2xl border border-line bg-surface p-6 shadow-[var(--shadow)]">
      <h2 className="font-[family-name:var(--font-display)] text-lg font-semibold text-ink">{title}</h2>
      {rows.length === 0 ? (
        <p className="mt-3 text-sm text-muted">—</p>
      ) : (
        <ul className="mt-4 flex flex-col gap-3">
          {rows.map((r, i) => (
            <li key={i}>
              <div className="mb-1 flex items-baseline justify-between gap-3">
                <span className="truncate text-sm text-ink">{r.label}</span>
                <span className="shrink-0 text-sm text-muted">
                  <span className="font-medium text-ink tnum">{r.value}</span> {unit}
                </span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-surface-2">
                <span className="block h-full rounded-full bg-accent" style={{ width: `${(r.value / max) * 100}%` }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function DashboardSkeleton() {
  return (
    <div className="mx-auto max-w-5xl animate-pulse">
      <div className="h-3 w-24 rounded bg-surface-2" />
      <div className="mt-2 h-8 w-64 rounded bg-surface-2" />
      <div className="mt-6 grid gap-4 md:grid-cols-[1.3fr_1fr]">
        <div className="h-40 rounded-2xl bg-surface-2" />
        <div className="h-40 rounded-2xl bg-surface-2" />
      </div>
      <div className="mt-4 grid gap-4 md:grid-cols-2">
        <div className="h-48 rounded-2xl bg-surface-2" />
        <div className="h-48 rounded-2xl bg-surface-2" />
      </div>
    </div>
  );
}
