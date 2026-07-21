"use client";

import { useEffect, useMemo, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { ApiError, get, post } from "@/lib/api";
import { Link } from "@/i18n/navigation";
import LocaleSwitcher from "@/components/LocaleSwitcher";
import { Button, Field, Input, Spinner } from "@/components/ui/kit";

type Salon = { name: string; slug: string; brand_color: string | null; logo_path: string | null; timezone: string };
type Branch = { id: number; name: string; city: string | null };
type StaffLite = { id: number; name: string; title: string | null };
type Service = {
  id: number; name: string; duration_min: number; price: string; currency: string;
  category?: { name: string } | null; staff?: StaffLite[];
};
type Slot = { time: string; staff: { id: number; name: string }[] };

const STEPS = ["service", "time", "details", "verify"] as const;

export default function BookingFlow({ slug }: { slug: string }) {
  const t = useTranslations("book");
  const locale = useLocale();

  const [salon, setSalon] = useState<Salon | null>(null);
  const [branches, setBranches] = useState<Branch[]>([]);
  const [services, setServices] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

  const [step, setStep] = useState(0);
  const [branchId, setBranchId] = useState<number | null>(null);
  const [service, setService] = useState<Service | null>(null);
  const [staffId, setStaffId] = useState<number | null>(null); // null = any
  const [date, setDate] = useState("");
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [pick, setPick] = useState<{ time: string; staffId: number } | null>(null);

  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [debugCode, setDebugCode] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [existingToken, setExistingToken] = useState<string | null>(null);
  const [result, setResult] = useState<{ reference: string; manage_token: string; starts_at: string; service: string; staff: string } | null>(null);

  // Load salon + branches + services.
  useEffect(() => {
    let live = true;
    Promise.all([
      get<{ data: Salon }>(`/book/${slug}`),
      get<{ data: Branch[] }>(`/book/${slug}/branches`),
      get<{ data: Service[] }>(`/book/${slug}/services`),
    ])
      .then(([s, b, sv]) => {
        if (!live) return;
        setSalon(s.data);
        setBranches(b.data);
        setServices(sv.data);
        if (b.data.length === 1) setBranchId(b.data[0].id);
      })
      .catch(() => live && setNotFound(true))
      .finally(() => live && setLoading(false));
    return () => { live = false; };
  }, [slug]);

  // Next 14 days.
  const days = useMemo(() => {
    const out: string[] = [];
    const d = new Date();
    for (let i = 0; i < 14; i++) {
      const x = new Date(d);
      x.setDate(d.getDate() + i);
      out.push(`${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, "0")}-${String(x.getDate()).padStart(2, "0")}`);
    }
    return out;
  }, []);

  useEffect(() => { if (!date && days.length) setDate(days[0]); }, [days, date]);

  // Load availability when service/branch/staff/date are set and we're on the time step.
  useEffect(() => {
    if (step !== 1 || !service || !branchId || !date) return;
    let live = true;
    setSlotsLoading(true);
    setPick(null);
    const staffParam = staffId ? `&staff_id=${staffId}` : "";
    get<{ data: Slot[] }>(`/book/${slug}/availability?branch_id=${branchId}&service_id=${service.id}${staffParam}&date=${date}`)
      .then((r) => live && setSlots(r.data))
      .catch(() => live && setSlots([]))
      .finally(() => live && setSlotsLoading(false));
    return () => { live = false; };
  }, [step, slug, service, branchId, staffId, date]);

  const money = (p: string, cur: string) =>
    new Intl.NumberFormat(locale, { style: "currency", currency: cur, minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(p));
  const dayLabel = (iso: string) =>
    new Intl.DateTimeFormat(locale, { weekday: "short", day: "numeric", month: "short", timeZone: salon?.timezone }).format(new Date(iso + "T12:00:00"));
  const whenLabel = (iso: string) =>
    new Intl.DateTimeFormat(locale, { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit", timeZone: salon?.timezone }).format(new Date(iso));

  async function sendCode() {
    setError(null);
    setBusy(true);
    try {
      const r = await post<{ debug_code?: string }>(`/book/${slug}/otp`, { phone });
      setDebugCode(r.debug_code ?? null); // only returned outside production
      setStep(3);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Something went wrong.");
    } finally { setBusy(false); }
  }

  async function confirm() {
    if (!service || !branchId || !pick) return;
    setError(null);
    setExistingToken(null);
    setBusy(true);
    try {
      const res = await post<{ data: { reference: string; manage_token: string; starts_at: string; service: string; staff: string } }>(
        `/book/${slug}/appointments`,
        { branch_id: branchId, service_id: service.id, staff_id: pick.staffId, date, time: pick.time, name, phone, code },
      );
      setResult(res.data);
      setStep(4);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) { setError(t("wrongCode")); }
      else if (e instanceof ApiError && e.status === 409) {
        setError(e.message);
        const existing = (e.body as { existing?: { manage_token?: string } })?.existing;
        if (existing?.manage_token) setExistingToken(existing.manage_token);
      } else { setError(e instanceof ApiError ? e.message : "Something went wrong."); }
    } finally { setBusy(false); }
  }

  const brand = salon?.brand_color || "#1E5C4A";
  const themeStyle = {
    "--accent": brand,
    "--accent-ink": brand,
    "--accent-soft": `color-mix(in srgb, ${brand} 12%, transparent)`,
    "--on-accent": "#ffffff",
  } as React.CSSProperties;

  if (loading) return <div className="grid min-h-dvh place-items-center bg-ground"><Spinner /></div>;
  if (notFound || !salon) {
    return <div className="grid min-h-dvh place-items-center bg-ground px-6 text-center text-muted">{t("notFound")}</div>;
  }

  const eligibleStaff = service?.staff ?? [];

  return (
    <div style={themeStyle} className="min-h-dvh bg-ground">
      <header className="mx-auto flex max-w-lg items-center justify-between px-5 py-4">
        <span className="font-[family-name:var(--font-display)] text-xl font-semibold text-ink">{salon.name}</span>
        <LocaleSwitcher />
      </header>

      <main className="mx-auto max-w-lg px-5 pb-16">
        {step < 4 && (
          <div className="mb-6">
            <div className="mb-2 flex gap-1.5">
              {STEPS.map((_, i) => (
                <span key={i} className={`h-1.5 flex-1 rounded-full ${i <= step ? "bg-accent" : "bg-surface-2"}`} />
              ))}
            </div>
            <p className="font-mono text-xs uppercase tracking-widest text-muted">
              {t("step", { n: step + 1, total: STEPS.length })}
            </p>
          </div>
        )}

        {/* Step 1 — service (+ branch) */}
        {step === 0 && (
          <div>
            {branches.length > 1 && (
              <Field label={t("branch")}>
                <select
                  value={branchId ?? ""}
                  onChange={(e) => setBranchId(Number(e.target.value))}
                  className="mb-5 w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-ink outline-none focus:border-accent"
                >
                  <option value="" disabled>—</option>
                  {branches.map((b) => <option key={b.id} value={b.id}>{b.name}{b.city ? ` · ${b.city}` : ""}</option>)}
                </select>
              </Field>
            )}
            <h1 className="mb-4 font-[family-name:var(--font-display)] text-2xl font-semibold text-ink">{t("chooseService")}</h1>
            <div className="grid gap-2.5">
              {services.map((s) => (
                <button
                  key={s.id}
                  disabled={!branchId}
                  onClick={() => { setService(s); setStaffId(null); setStep(1); }}
                  className="flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-4 text-start shadow-[var(--shadow)] transition-colors hover:border-accent disabled:opacity-50"
                >
                  <span className="min-w-0">
                    <span className="block font-medium text-ink">{s.name}</span>
                    <span className="block text-sm text-muted">{s.duration_min} {t("min")}</span>
                  </span>
                  <span className="shrink-0 font-[family-name:var(--font-display)] font-semibold text-gold tnum">
                    {money(s.price, s.currency)}
                  </span>
                </button>
              ))}
            </div>
          </div>
        )}

        {/* Step 2 — staff + date + time */}
        {step === 1 && service && (
          <div>
            <h1 className="mb-4 font-[family-name:var(--font-display)] text-2xl font-semibold text-ink">{t("chooseTime")}</h1>

            {eligibleStaff.length > 0 && (
              <div className="mb-4 flex flex-wrap gap-2">
                <Chip active={staffId === null} onClick={() => setStaffId(null)}>{t("anyStaff")}</Chip>
                {eligibleStaff.map((m) => (
                  <Chip key={m.id} active={staffId === m.id} onClick={() => setStaffId(m.id)}>{m.name}</Chip>
                ))}
              </div>
            )}

            <div className="mb-4 flex gap-2 overflow-x-auto pb-1" dir="ltr">
              {days.map((d) => (
                <button
                  key={d}
                  onClick={() => setDate(d)}
                  className={`shrink-0 rounded-xl border px-3 py-2 text-center text-sm transition-colors ${
                    date === d ? "border-accent bg-accent text-on-accent" : "border-line text-ink hover:border-accent"
                  }`}
                >
                  {dayLabel(d)}
                </button>
              ))}
            </div>

            {slotsLoading ? <Spinner inline /> : !slots || slots.length === 0 ? (
              <p className="rounded-xl bg-surface-2 px-4 py-6 text-center text-sm text-muted">{t("noSlots")}</p>
            ) : (
              <div className="flex flex-wrap gap-2" dir="ltr">
                {slots.map((s) => {
                  const sid = staffId ?? s.staff[0]?.id;
                  const selected = pick?.time === s.time;
                  return (
                    <button
                      key={s.time}
                      onClick={() => sid && setPick({ time: s.time, staffId: sid })}
                      className={`rounded-lg border px-3.5 py-2 text-sm font-medium tnum transition-colors ${
                        selected ? "border-accent bg-accent text-on-accent" : "border-line text-ink hover:border-accent"
                      }`}
                    >
                      {s.time}
                    </button>
                  );
                })}
              </div>
            )}

            <div className="mt-6 flex gap-2">
              <Button variant="ghost" onClick={() => setStep(0)}>{t("back")}</Button>
              <Button className="flex-1" disabled={!pick} onClick={() => setStep(2)}>{t("next")}</Button>
            </div>
          </div>
        )}

        {/* Step 3 — details */}
        {step === 2 && (
          <div>
            <h1 className="mb-4 font-[family-name:var(--font-display)] text-2xl font-semibold text-ink">{t("yourDetails")}</h1>
            <div className="flex flex-col gap-4">
              <Field label={t("name")}><Input value={name} onChange={(e) => setName(e.target.value)} /></Field>
              <Field label={t("phone")}>
                <Input type="tel" dir="ltr" placeholder="+9665…" value={phone} onChange={(e) => setPhone(e.target.value)} />
              </Field>
              {error && <p className="rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">{error}</p>}
              <div className="flex gap-2">
                <Button variant="ghost" onClick={() => setStep(1)}>{t("back")}</Button>
                <Button className="flex-1" disabled={busy || name.trim().length < 2 || phone.trim().length < 9} onClick={sendCode}>
                  {busy ? t("sending") : t("sendCode")}
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Step 4 — verify */}
        {step === 3 && (
          <div>
            <h1 className="mb-2 font-[family-name:var(--font-display)] text-2xl font-semibold text-ink">{t("verify")}</h1>
            <p className="mb-4 text-sm text-muted">{t("codeSent", { phone })}</p>
            {debugCode && (
              <p className="mb-4 rounded-lg bg-gold-soft px-3 py-2 text-center text-sm text-gold" dir="ltr">
                Test mode — your code is <b className="tnum">{debugCode}</b>
              </p>
            )}
            <div className="flex flex-col gap-4">
              <Field label={t("code")}>
                <Input inputMode="numeric" dir="ltr" maxLength={6} className="tracking-[0.4em]" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} />
              </Field>
              {error && (
                <div className="rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">
                  <p>{error}</p>
                  {existingToken && (
                    <Link href={`/book/manage/${existingToken}`} className="mt-1 inline-block font-medium underline">
                      {t("manageExisting")}
                    </Link>
                  )}
                </div>
              )}
              <div className="flex gap-2">
                <Button variant="ghost" onClick={() => setStep(2)}>{t("back")}</Button>
                <Button className="flex-1" disabled={busy || code.length !== 6} onClick={confirm}>
                  {busy ? t("confirming") : t("confirm")}
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Step 5 — done */}
        {step === 4 && result && (
          <div className="pt-6 text-center">
            <span className="mx-auto mb-5 grid size-16 place-items-center rounded-full bg-accent-soft text-accent">
              <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2"><path d="M5 12l5 5L20 6" /></svg>
            </span>
            <h1 className="font-[family-name:var(--font-display)] text-3xl font-semibold text-ink">{t("done")}</h1>
            <p className="mt-2 text-muted">{t("doneSub")}</p>
            <div className="mx-auto mt-6 max-w-sm rounded-2xl border border-line bg-surface p-5 text-start shadow-[var(--shadow)]">
              <p className="font-medium text-ink">{result.service}</p>
              <p className="text-sm text-muted">{t("with")} {result.staff}</p>
              <p className="mt-2 text-sm text-ink">{whenLabel(result.starts_at)}</p>
              <p className="mt-3 font-mono text-xs text-muted" dir="ltr">{t("reference")}: {result.reference}</p>
            </div>
            <Link href={`/book/manage/${result.manage_token}`} className="mt-4 inline-block text-sm font-medium text-accent-ink hover:underline">
              {t("manageExisting")}
            </Link>
            <button
              onClick={() => { setStep(0); setService(null); setPick(null); setName(""); setPhone(""); setCode(""); setResult(null); }}
              className="mt-6 text-sm font-medium text-accent-ink hover:underline"
            >
              {t("bookAnother")}
            </button>
          </div>
        )}

        <p className="mt-10 text-center font-mono text-[11px] uppercase tracking-widest text-muted/70">{t("poweredBy")}</p>
      </main>
    </div>
  );
}

function Chip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className={`rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors ${
        active ? "border-accent bg-accent text-on-accent" : "border-line text-ink hover:border-accent"
      }`}
    >
      {children}
    </button>
  );
}
