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
  cancelled_by?: string | null; cancellation_reason?: string | null;
  customer: { name: string; phone: string } | null;
  service: { name: string; name_en?: string | null; duration_min: number } | null;
  staff: { name: string } | null;
  customer_note?: string | null;
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
  const [refSearch, setRefSearch] = useState("");
  const [searchResult, setSearchResult] = useState<Appt[] | null>(null);
  const [searchLoading, setSearchLoading] = useState(false);
  const [cancelFor, setCancelFor] = useState<Appt | null>(null);
  const searching = refSearch.trim().length >= 2;

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

  const time = (iso: string) =>
    new Intl.DateTimeFormat(locale, { hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));
  const dayTime = (iso: string) =>
    new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: tz }).format(new Date(iso));

  // Search bookings by reference (across all dates).
  const refReload = () => {
    const ref = refSearch.trim();
    if (ref.length < 2) { setSearchResult(null); return; }
    setSearchLoading(true);
    get<{ data: Appt[] }>(`/appointments?reference=${encodeURIComponent(ref)}`)
      .then((r) => setSearchResult(r.data))
      .catch(() => setSearchResult([]))
      .finally(() => setSearchLoading(false));
  };
  useEffect(() => {
    const id = setTimeout(refReload, 350);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [refSearch]);

  async function setStatusAny(a: Appt, status: string, reason?: string) {
    try {
      await patch(`/appointments/${a.id}/status`, { status, ...(reason ? { reason } : {}) });
      reload(); refReload(); notify(c("saved"));
    } catch { notify(c("error"), "error"); }
  }

  const apptRow = (a: Appt, withDate = false) => (
    <Card key={a.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
      <span className="shrink-0 whitespace-nowrap font-mono text-sm text-accent-ink tnum" dir="ltr">
        {withDate ? dayTime(a.starts_at) : time(a.starts_at)}
      </span>
      <div className="min-w-0 flex-1">
        <p className="font-medium text-ink">{a.customer?.name}</p>
        <p className="text-sm text-muted">{locale === "en" && a.service?.name_en ? a.service.name_en : a.service?.name} · {t("with")} {a.staff?.name}</p>
        {a.status === "cancelled" && (
          <p className="mt-0.5 text-sm text-muted">
            {a.cancelled_by === "customer" ? t("cbCustomer") : t("cbAdmin")}
            {a.cancellation_reason ? ` · ${a.cancellation_reason}` : ""}
          </p>
        )}
        {a.customer_note && (
          <p className="mt-1 flex items-start gap-1.5 rounded-lg bg-gold-soft px-2.5 py-1.5 text-sm text-ink">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="mt-0.5 shrink-0 text-gold"><path d="M4 5h16M4 12h16M4 19h10" /></svg>
            <span className="min-w-0">{a.customer_note}</span>
          </p>
        )}
      </div>
      <Badge tone={STATUS_TONE[a.status]}>{statusLabel(a.status)}</Badge>
      {a.status === "pending" && (
        <div className="flex flex-wrap gap-1.5">
          <Button onClick={() => setStatusAny(a, "confirmed")}>{t("confirm")}</Button>
          <Button variant="danger" onClick={() => setCancelFor(a)}>{t("markCancelled")}</Button>
        </div>
      )}
      {a.status === "confirmed" && (
        <div className="flex flex-wrap gap-1.5">
          <Button onClick={() => setStatusAny(a, "done")}>{t("markDone")}</Button>
          <Button variant="ghost" onClick={() => setStatusAny(a, "no_show")}>{t("markNoShow")}</Button>
          <Button variant="danger" onClick={() => setCancelFor(a)}>{t("markCancelled")}</Button>
        </div>
      )}
    </Card>
  );

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")}>
        <input
          value={refSearch}
          onChange={(e) => setRefSearch(e.target.value.toUpperCase())}
          placeholder={t("searchRef")}
          dir="ltr"
          className="w-40 rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-ink outline-none placeholder:text-muted/60 focus:border-accent"
        />
        <Button onClick={() => setWalkIn(true)}>+ {t("walkIn")}</Button>
      </PageHeader>

      {searching ? (
        <div>
          <p className="mb-3 font-mono text-xs uppercase tracking-widest text-muted">{t("searchResult")}</p>
          {searchLoading ? <Spinner /> : !searchResult || searchResult.length === 0 ? (
            <p className="rounded-xl bg-surface-2 px-4 py-8 text-center text-sm text-muted">{t("searchNotFound")}</p>
          ) : (
            <div className="grid gap-2.5">{searchResult.map((a) => apptRow(a, true))}</div>
          )}
        </div>
      ) : (
        <>
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
            <div className="grid gap-2.5">{data.data.map((a) => apptRow(a))}</div>
          )}
        </>
      )}

      {walkIn && salon && (
        <WalkInModal slug={salon.slug} defaultDate={date} onClose={() => setWalkIn(false)}
          onSaved={() => { setWalkIn(false); reload(); notify(t("booked")); }} />
      )}

      {cancelFor && (
        <CancelModal
          onClose={() => setCancelFor(null)}
          onConfirm={(reason) => { void setStatusAny(cancelFor, "cancelled", reason); setCancelFor(null); }}
        />
      )}
    </div>
  );
}

function CancelModal({ onClose, onConfirm }: { onClose: () => void; onConfirm: (reason: string) => void }) {
  const t = useTranslations("app.calendar");
  const [reason, setReason] = useState("");
  return (
    <Modal open onClose={onClose} title={t("cancelModalTitle")}>
      <div className="flex flex-col gap-4">
        <Field label={t("cancelReasonLabel")}>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder={t("cancelReasonPlaceholder")} />
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t("keepBooking")}</Button>
          <Button variant="danger" onClick={() => onConfirm(reason)}>{t("cancelConfirmBtn")}</Button>
        </div>
      </div>
    </Modal>
  );
}

type Slot = { time: string };

function WalkInModal({ slug, defaultDate, onClose, onSaved }: {
  slug: string; defaultDate: string; onClose: () => void; onSaved: () => void;
}) {
  const t = useTranslations("app.calendar");
  const c = useTranslations("app.common");
  const locale = useLocale();
  const { notify } = useToast();
  const branches = useApi<{ data: { id: number; name: string }[] }>("/branches");
  const services = useApi<{ data: { id: number; name: string; name_en?: string | null }[] }>("/services");
  const staff = useApi<{ data: { id: number; name: string }[] }>("/staff");
  const [form, setForm] = useState({
    branch_id: "", service_id: "", staff_id: "",
    customer_name: "", customer_phone: "", date: defaultDate, time: "", note: "",
  });
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value, ...(k !== "customer_name" && k !== "customer_phone" ? { time: "" } : {}) }));

  // Customer lookup — pick an existing profile instead of retyping.
  const [custSearch, setCustSearch] = useState("");
  const [custMatches, setCustMatches] = useState<{ id: number; name: string; phone: string }[]>([]);
  const [showMatches, setShowMatches] = useState(false);

  useEffect(() => {
    const term = custSearch.trim();
    if (term.length < 2) { setCustMatches([]); return; }
    let live = true;
    const id = setTimeout(() => {
      get<{ data: { id: number; name: string; phone: string }[] }>(`/customers?q=${encodeURIComponent(term)}`)
        .then((r) => { if (live) { setCustMatches(r.data); setShowMatches(true); } })
        .catch(() => {});
    }, 300);
    return () => { live = false; clearTimeout(id); };
  }, [custSearch]);

  function pickCustomer(cust: { name: string; phone: string }) {
    setForm((f) => ({ ...f, customer_name: cust.name, customer_phone: cust.phone }));
    setCustSearch("");
    setShowMatches(false);
  }

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
        note: form.note.trim() || undefined,
      });
      onSaved();
    } catch (err) {
      // Surface the real reason (e.g. slot taken, bad phone) instead of a generic message.
      notify(err instanceof ApiError ? err.message : c("error"), "error");
      setBusy(false);
    }
  }

  if (branches.loading || services.loading || staff.loading) {
    return <Modal open onClose={onClose} title={t("newWalkIn")}><Spinner inline /></Modal>;
  }

  return (
    <Modal open onClose={onClose} title={t("newWalkIn")}>
      <form onSubmit={save} className="flex flex-col gap-3.5">
        {/* Pick an existing customer, or fill the fields for a new one */}
        <div className="relative">
          <Field label={t("existingCustomer")}>
            <Input
              value={custSearch}
              onChange={(e) => setCustSearch(e.target.value)}
              onFocus={() => custMatches.length > 0 && setShowMatches(true)}
              placeholder={t("searchCustomer")}
            />
          </Field>
          {showMatches && custSearch.trim().length >= 2 && (
            <ul className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-xl border border-line bg-surface shadow-[var(--shadow-lg)]">
              {custMatches.length === 0 ? (
                <li className="px-3 py-2 text-sm text-muted">{t("noCustomerMatch")}</li>
              ) : custMatches.map((m) => (
                <li key={m.id}>
                  <button
                    type="button"
                    onClick={() => pickCustomer(m)}
                    className="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm transition-colors hover:bg-surface-2"
                  >
                    <span className="text-ink">{m.name}</span>
                    <span className="text-muted" dir="ltr">{m.phone}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t("customer")}><Input required value={form.customer_name} onChange={set("customer_name")} /></Field>
          <Field label={t("customerPhone")}><Input required dir="ltr" placeholder="+9665…" value={form.customer_phone} onChange={set("customer_phone")} /></Field>
        </div>
        <Field label={t("branch")}>
          <Select required value={form.branch_id} onChange={set("branch_id")}>
            <option value="" disabled>—</option>
            {branches.data!.data.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select>
        </Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t("service")}>
            <Select required value={form.service_id} onChange={set("service_id")}>
              <option value="" disabled>—</option>
              {services.data!.data.map((s) => <option key={s.id} value={s.id}>{locale === "en" && s.name_en ? s.name_en : s.name}</option>)}
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
            <Spinner inline />
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
                      ? "border-accent bg-accent text-on-accent"
                      : "border-line text-ink hover:border-accent"
                  }`}
                >
                  {s.time}
                </button>
              ))}
            </div>
          )}
        </div>

        <Field label={t("note")}>
          <textarea
            rows={2}
            maxLength={500}
            placeholder={t("notePlaceholder")}
            value={form.note}
            onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))}
            className="w-full resize-none rounded-xl border border-line bg-surface px-3.5 py-2.5 text-ink outline-none placeholder:text-muted/60 focus:border-accent"
          />
        </Field>

        <div className="mt-1 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
          <Button type="submit" disabled={busy || !form.time}>{busy ? c("saving") : t("book")}</Button>
        </div>
      </form>
    </Modal>
  );
}
