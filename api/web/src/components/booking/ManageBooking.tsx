"use client";

import { useEffect, useMemo, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { ApiError, get, post } from "@/lib/api";
import { Link } from "@/i18n/navigation";
import LocaleSwitcher from "@/components/LocaleSwitcher";
import { Badge, Button, Spinner } from "@/components/ui/kit";

type Booking = {
  reference: string;
  status: string;
  starts_at: string;
  service: { name: string; duration_min: number } | null;
  staff: { name: string } | null;
  branch: { name: string; address: string | null } | null;
  salon: { name: string; slug: string; brand_color: string | null; timezone: string } | null;
};
type Slot = { time: string };

const STATUS_TONE: Record<string, string> = {
  confirmed: "accent", pending: "gold", done: "gold", no_show: "warn", cancelled: "muted",
};

export default function ManageBooking({ token }: { token: string }) {
  const t = useTranslations("book");
  const locale = useLocale();

  const [b, setB] = useState<Booking | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [busy, setBusy] = useState(false);

  const [rescheduling, setRescheduling] = useState(false);
  const [date, setDate] = useState("");
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [pick, setPick] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = () => {
    setLoading(true);
    get<{ data: Booking }>(`/book/manage/${token}`)
      .then((r) => setB(r.data))
      .catch(() => setNotFound(true))
      .finally(() => setLoading(false));
  };
  useEffect(load, [token]);

  const days = useMemo(() => {
    const out: string[] = [];
    const d = new Date();
    for (let i = 0; i < 14; i++) {
      const x = new Date(d); x.setDate(d.getDate() + i);
      out.push(`${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, "0")}-${String(x.getDate()).padStart(2, "0")}`);
    }
    return out;
  }, []);
  useEffect(() => { if (rescheduling && !date) setDate(days[0]); }, [rescheduling, days, date]);

  useEffect(() => {
    if (!rescheduling || !date) return;
    let live = true;
    setSlotsLoading(true); setPick(null);
    get<{ data: Slot[] }>(`/book/manage/${token}/availability?date=${date}`)
      .then((r) => live && setSlots(r.data))
      .catch(() => live && setSlots([]))
      .finally(() => live && setSlotsLoading(false));
    return () => { live = false; };
  }, [rescheduling, date, token]);

  const tz = b?.salon?.timezone;
  const whenLabel = (iso: string) =>
    new Intl.DateTimeFormat(locale, { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
  const dayLabel = (iso: string) =>
    new Intl.DateTimeFormat(locale, { weekday: "short", day: "numeric", month: "short", timeZone: tz }).format(new Date(iso + "T12:00:00"));
  const statusLabel = (s: string) =>
    ({ confirmed: t("statusConfirmed"), pending: t("statusPending"), done: t("statusDone"), no_show: t("statusNoShow"), cancelled: t("statusCancelled") } as Record<string, string>)[s] ?? s;

  async function cancel() {
    if (!confirm(t("cancelConfirm"))) return;
    setBusy(true); setError(null);
    try { await post(`/book/manage/${token}/cancel`); load(); }
    catch (e) { setError(e instanceof ApiError ? e.message : "Error"); }
    finally { setBusy(false); }
  }

  async function reschedule() {
    if (!pick) return;
    setBusy(true); setError(null);
    try {
      await post(`/book/manage/${token}/reschedule`, { date, time: pick });
      setRescheduling(false); load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Error");
    } finally { setBusy(false); }
  }

  const brand = b?.salon?.brand_color || "#1E5C4A";
  const themeStyle = {
    "--accent": brand, "--accent-ink": brand,
    "--accent-soft": `color-mix(in srgb, ${brand} 12%, transparent)`, "--on-accent": "#ffffff",
  } as React.CSSProperties;

  if (loading) return <div className="grid min-h-dvh place-items-center bg-ground"><Spinner /></div>;
  if (notFound || !b) return <div className="grid min-h-dvh place-items-center bg-ground px-6 text-center text-muted">{t("notFoundManage")}</div>;

  const canChange = (b.status === "confirmed" || b.status === "pending") && new Date(b.starts_at) > new Date();

  return (
    <div style={themeStyle} className="min-h-dvh bg-ground">
      <header className="mx-auto flex max-w-lg items-center justify-between gap-3 px-5 py-4">
        <span className="min-w-0 flex-1 truncate font-[family-name:var(--font-display)] text-xl font-semibold text-ink">{b.salon?.name}</span>
        <span className="shrink-0"><LocaleSwitcher /></span>
      </header>

      <main className="mx-auto max-w-lg px-5 pb-16">
        {b.status === "cancelled" ? (
          <div className="pt-8 text-center">
            <h1 className="font-[family-name:var(--font-display)] text-2xl font-semibold text-ink">{t("cancelled")}</h1>
            <p className="mt-2 text-muted">{t("cancelledSub")}</p>
            {b.salon?.slug && (
              <Link
                href={`/book/${b.salon.slug}`}
                className="mt-6 inline-block rounded-xl bg-accent px-5 py-2.5 text-sm font-medium text-on-accent"
              >
                {t("backToBook")}
              </Link>
            )}
          </div>
        ) : (
          <>
            <div className="mb-4 flex items-center justify-between">
              <h1 className="font-[family-name:var(--font-display)] text-2xl font-semibold text-ink">{t("manageTitle")}</h1>
              <Badge tone={STATUS_TONE[b.status]}>{statusLabel(b.status)}</Badge>
            </div>

            <div className="rounded-2xl border border-line bg-surface p-5 shadow-[var(--shadow)]">
              <p className="font-medium text-ink">{b.service?.name}</p>
              <p className="text-sm text-muted">{t("with")} {b.staff?.name}</p>
              <p className="mt-2 text-ink">{whenLabel(b.starts_at)}</p>
              {b.branch?.name && <p className="text-sm text-muted">{b.branch.name}</p>}
              <p className="mt-3 font-mono text-xs text-muted" dir="ltr">{t("reference")}: {b.reference}</p>
            </div>

            {error && <p className="mt-4 rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">{error}</p>}

            {rescheduling ? (
              <div className="mt-6">
                <h2 className="mb-3 font-[family-name:var(--font-display)] text-lg font-semibold text-ink">{t("pickNewTime")}</h2>
                <div className="mb-4 flex gap-2 overflow-x-auto pb-1" dir="ltr">
                  {days.map((d) => (
                    <button key={d} onClick={() => setDate(d)}
                      className={`flex min-h-11 shrink-0 items-center justify-center rounded-xl border px-3.5 py-2 text-sm transition-colors ${date === d ? "border-accent bg-accent text-on-accent" : "border-line text-ink hover:border-accent"}`}>
                      {dayLabel(d)}
                    </button>
                  ))}
                </div>
                {slotsLoading ? <Spinner inline /> : !slots || slots.length === 0 ? (
                  <p className="rounded-xl bg-surface-2 px-4 py-6 text-center text-sm text-muted">{t("noSlots")}</p>
                ) : (
                  <div className="flex flex-wrap gap-2" dir="ltr">
                    {slots.map((s) => (
                      <button key={s.time} onClick={() => setPick(s.time)}
                        className={`inline-flex min-h-11 items-center rounded-lg border px-4 py-2.5 text-sm font-medium tnum transition-colors ${pick === s.time ? "border-accent bg-accent text-on-accent" : "border-line text-ink hover:border-accent"}`}>
                        {s.time}
                      </button>
                    ))}
                  </div>
                )}
                <div className="mt-6 flex gap-2">
                  <Button variant="ghost" onClick={() => setRescheduling(false)}>{t("keep")}</Button>
                  <Button className="flex-1" disabled={busy || !pick} onClick={reschedule}>{busy ? t("confirming") : t("confirm")}</Button>
                </div>
              </div>
            ) : canChange ? (
              <div className="mt-6 flex gap-2">
                <Button variant="ghost" className="flex-1" onClick={() => setRescheduling(true)}>{t("reschedule")}</Button>
                <Button variant="danger" className="flex-1" disabled={busy} onClick={cancel}>{t("cancel")}</Button>
              </div>
            ) : (
              <p className="mt-6 text-center text-sm text-muted">{t("cannotChange")}</p>
            )}
          </>
        )}

        <p className="mt-10 text-center font-mono text-[11px] uppercase tracking-widest text-muted/70">{t("poweredBy")}</p>
      </main>
    </div>
  );
}
