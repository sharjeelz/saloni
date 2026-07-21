"use client";

import { useEffect, useMemo, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { ApiError, get, patch, post } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useAuth } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Modal";
import {
  Badge, Button, Card, EmptyState, Field, Input, LoadError, PageHeader, Select, Spinner,
} from "@/components/ui/kit";

type Appt = {
  id: number; starts_at: string; status: string;
  customer: { name: string; phone: string } | null;
  service: { name: string; duration_min: number } | null;
  staff: { name: string } | null;
};

const STATUS_TONE: Record<string, string> = {
  confirmed: "accent", done: "gold", no_show: "warn", cancelled: "muted", pending: "gold",
};

function isoDate(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

export default function CalendarPage() {
  const t = useTranslations("app.calendar");
  const c = useTranslations("app.common");
  const td = useTranslations("app.dashboard");
  const locale = useLocale();
  const { salon } = useAuth();
  const tz = salon?.timezone;
  const { notify } = useToast();
  const [date, setDate] = useState(() => isoDate(new Date()));
  const [walkIn, setWalkIn] = useState(false);

  const statusLabel = (s: string): string =>
    ({
      confirmed: td("statusConfirmed"),
      done: td("statusDone"),
      no_show: td("statusNoShow"),
      cancelled: td("statusCancelled"),
      pending: t("statusPending"),
    } as Record<string, string>)[s] ?? s;

  const { data, loading, error, reload } = useApi<{ data: Appt[] }>(
    `/appointments?from=${date}&to=${date}`,
  );

  const shift = (days: number) => {
    const d = new Date(date + "T12:00:00");
    d.setDate(d.getDate() + days);
    setDate(isoDate(d));
  };

  const heading = useMemo(
    () => new Intl.DateTimeFormat(locale, { weekday: "long", day: "numeric", month: "long" }).format(new Date(date + "T12:00:00")),
    [date, locale],
  );

  async function setStatus(a: Appt, status: string) {
    try { await patch(`/appointments/${a.id}/status`, { status }); reload(); notify(c("saved")); }
    catch { notify(c("error"), "error"); }
  }

  const time = (iso: string) =>
    new Intl.DateTimeFormat(locale, { hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")}>
        <Button onClick={() => setWalkIn(true)}>+ {t("walkIn")}</Button>
      </PageHeader>

      <div className="mb-5 flex items-center justify-between gap-3">
        <div className="flex items-center gap-1.5">
          <button onClick={() => shift(-1)} aria-label={t("prev")}
            className="grid size-9 place-items-center rounded-full border border-line text-muted hover:text-ink">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path className="rtl:hidden" d="M15 18l-6-6 6-6" /><path className="hidden rtl:block" d="M9 18l6-6-6-6" /></svg>
          </button>
          <Button variant="ghost" onClick={() => setDate(isoDate(new Date()))}>{t("today")}</Button>
          <button onClick={() => shift(1)} aria-label={t("next")}
            className="grid size-9 place-items-center rounded-full border border-line text-muted hover:text-ink">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path className="rtl:hidden" d="M9 18l6-6-6-6" /><path className="hidden rtl:block" d="M15 18l-6-6 6-6" /></svg>
          </button>
        </div>
        <p className="font-[family-name:var(--font-display)] text-lg font-semibold text-ink">{heading}</p>
      </div>

      {loading ? <Spinner /> : error || !data ? <LoadError onRetry={reload} /> : data.data.length === 0 ? (
        <EmptyState message={t("empty")} action={<Button onClick={() => setWalkIn(true)}>+ {t("walkIn")}</Button>} />
      ) : (
        <div className="grid gap-2.5">
          {data.data.map((a) => (
            <Card key={a.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
              <span className="w-14 font-mono text-sm text-accent-ink tnum" dir="ltr">{time(a.starts_at)}</span>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-ink">{a.customer?.name}</p>
                <p className="text-sm text-muted">{a.service?.name} · {t("with")} {a.staff?.name}</p>
              </div>
              <Badge tone={STATUS_TONE[a.status]}>{statusLabel(a.status)}</Badge>
              {a.status === "pending" && (
                <Button variant="ghost" onClick={() => setStatus(a, "confirmed")}>{t("confirm")}</Button>
              )}
              {(a.status === "confirmed" || a.status === "pending") && (
                <div className="flex gap-1.5">
                  <Button variant="ghost" onClick={() => setStatus(a, "done")}>{t("markDone")}</Button>
                  <Button variant="ghost" onClick={() => setStatus(a, "no_show")}>{t("markNoShow")}</Button>
                  <Button variant="danger" onClick={() => setStatus(a, "cancelled")}>{t("markCancelled")}</Button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}

      {walkIn && salon && (
        <WalkInModal slug={salon.slug} defaultDate={date} onClose={() => setWalkIn(false)}
          onSaved={() => { setWalkIn(false); reload(); notify(t("booked")); }} />
      )}
    </div>
  );
}

type Slot = { time: string };

function WalkInModal({ slug, defaultDate, onClose, onSaved }: {
  slug: string; defaultDate: string; onClose: () => void; onSaved: () => void;
}) {
  const t = useTranslations("app.calendar");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const branches = useApi<{ data: { id: number; name: string }[] }>("/branches");
  const services = useApi<{ data: { id: number; name: string }[] }>("/services");
  const staff = useApi<{ data: { id: number; name: string }[] }>("/staff");
  const [form, setForm] = useState({
    branch_id: "", service_id: "", staff_id: "",
    customer_name: "", customer_phone: "", date: defaultDate, time: "",
  });
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value, ...(k !== "customer_name" && k !== "customer_phone" ? { time: "" } : {}) }));

  const ready = form.branch_id && form.service_id && form.staff_id && form.date;

  // Load real available slots whenever the selection changes.
  useEffect(() => {
    if (!ready) { setSlots(null); return; }
    let live = true;
    setSlotsLoading(true);
    get<{ data: Slot[] }>(
      `/book/${slug}/availability?branch_id=${form.branch_id}&service_id=${form.service_id}&staff_id=${form.staff_id}&date=${form.date}`,
    )
      .then((r) => live && setSlots(r.data))
      .catch(() => live && setSlots([]))
      .finally(() => live && setSlotsLoading(false));
    return () => { live = false; };
  }, [ready, slug, form.branch_id, form.service_id, form.staff_id, form.date]);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!form.time) return;
    setBusy(true);
    try {
      await post("/appointments", {
        branch_id: Number(form.branch_id), service_id: Number(form.service_id), staff_id: Number(form.staff_id),
        customer_name: form.customer_name, customer_phone: form.customer_phone, date: form.date, time: form.time,
      });
      onSaved();
    } catch (err) {
      notify(err instanceof ApiError && err.status === 409 ? err.message : c("error"), "error");
      setBusy(false);
    }
  }

  if (branches.loading || services.loading || staff.loading) {
    return <Modal open onClose={onClose} title={t("newWalkIn")}><Spinner /></Modal>;
  }

  return (
    <Modal open onClose={onClose} title={t("newWalkIn")}>
      <form onSubmit={save} className="flex flex-col gap-3.5">
        <div className="grid grid-cols-2 gap-3">
          <Field label={t("customer")}><Input required value={form.customer_name} onChange={set("customer_name")} /></Field>
          <Field label={t("customerPhone")}><Input required dir="ltr" placeholder="+9665…" value={form.customer_phone} onChange={set("customer_phone")} /></Field>
        </div>
        <Field label={t("branch")}>
          <Select required value={form.branch_id} onChange={set("branch_id")}>
            <option value="" disabled>—</option>
            {branches.data!.data.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select>
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t("service")}>
            <Select required value={form.service_id} onChange={set("service_id")}>
              <option value="" disabled>—</option>
              {services.data!.data.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
          </Field>
          <Field label={t("staff")}>
            <Select required value={form.staff_id} onChange={set("staff_id")}>
              <option value="" disabled>—</option>
              {staff.data!.data.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
          </Field>
        </div>
        <Field label={t("date")}>
          <Input type="date" required dir="ltr" value={form.date} onChange={set("date")} />
        </Field>

        {/* Real availability — no free-form/past times */}
        <div>
          <span className="mb-1.5 block text-sm font-medium text-ink">{t("chooseSlot")}</span>
          {!ready ? (
            <p className="rounded-xl bg-surface-2 px-3 py-2.5 text-sm text-muted">{t("pickFirst")}</p>
          ) : slotsLoading ? (
            <div className="py-4"><Spinner /></div>
          ) : !slots || slots.length === 0 ? (
            <p className="rounded-xl bg-surface-2 px-3 py-2.5 text-sm text-muted">{t("noSlots")}</p>
          ) : (
            <div className="flex flex-wrap gap-2" dir="ltr">
              {slots.map((s) => (
                <button
                  key={s.time}
                  type="button"
                  onClick={() => setForm((f) => ({ ...f, time: s.time }))}
                  className={`rounded-lg border px-3 py-1.5 text-sm font-medium tnum transition-colors ${
                    form.time === s.time
                      ? "border-accent bg-accent text-white"
                      : "border-line text-ink hover:border-accent"
                  }`}
                >
                  {s.time}
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="mt-1 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
          <Button type="submit" disabled={busy || !form.time}>{busy ? c("saving") : t("book")}</Button>
        </div>
      </form>
    </Modal>
  );
}
