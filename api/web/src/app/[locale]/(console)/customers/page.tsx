"use client";

import { useEffect, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { ApiError, get, patch, post } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useAuth } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Modal";
import { Badge, Button, Card, EmptyState, Field, Input, LoadError, PageHeader, Spinner } from "@/components/ui/kit";

type Customer = {
  id: number; name: string; phone: string; email: string | null;
  last_visit_at: string | null; appointments_count?: number;
};
type HistoryItem = {
  id: number; starts_at: string; status: string; price: string;
  service: { name: string } | null; staff: { name: string } | null;
};

const STATUS_TONE: Record<string, string> = {
  confirmed: "accent", done: "gold", no_show: "warn", cancelled: "muted", pending: "gold",
};

export default function CustomersPage() {
  const t = useTranslations("app.customers");
  const [term, setTerm] = useState("");
  const [query, setQuery] = useState("");
  const [open, setOpen] = useState<Customer | null>(null);
  const [importing, setImporting] = useState(false);

  // Debounce the search input.
  useEffect(() => {
    const id = setTimeout(() => setQuery(term.trim()), 300);
    return () => clearTimeout(id);
  }, [term]);

  const { data, loading, error, reload } = useApi<{ data: Customer[] }>(
    `/customers${query ? `?q=${encodeURIComponent(query)}` : ""}`,
  );

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")}>
        <Button variant="ghost" onClick={() => setImporting(true)}>{t("import")}</Button>
      </PageHeader>

      <div className="mb-5">
        <Input
          value={term}
          onChange={(e) => setTerm(e.target.value)}
          placeholder={t("search")}
          className="max-w-md"
        />
      </div>

      {loading ? <Spinner /> : error || !data ? <LoadError onRetry={reload} /> : data.data.length === 0 ? (
        <EmptyState message={query ? t("noMatch") : t("empty")} />
      ) : (
        <div className="grid gap-2.5">
          {data.data.map((cust) => (
            <Card key={cust.id} className="p-0">
              <button
                onClick={() => setOpen(cust)}
                className="flex w-full items-center gap-3 p-4 text-start transition-colors hover:bg-surface-2"
              >
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-gold-soft font-semibold text-gold">
                  {cust.name.charAt(0)}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block font-medium text-ink">{cust.name}</span>
                  <span className="block text-sm text-muted" dir="ltr">{cust.phone}</span>
                </span>
                <span className="shrink-0 text-sm text-muted">
                  <span className="font-medium text-ink tnum">{cust.appointments_count ?? 0}</span> {t("bookings")}
                </span>
              </button>
            </Card>
          ))}
        </div>
      )}

      {open && <CustomerDrawer customer={open} onClose={() => setOpen(null)} onSaved={reload} />}
      {importing && <ImportModal onClose={() => setImporting(false)} onDone={reload} />}
    </div>
  );
}

function ImportModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const t = useTranslations("app.customers");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ created: number; updated: number; skipped: number } | null>(null);

  function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = () => setText((prev) => (prev ? prev + "\n" : "") + String(reader.result ?? ""));
    reader.readAsText(f);
    e.target.value = "";
  }

  async function run() {
    if (!text.trim()) return;
    setBusy(true);
    try {
      const r = await post<{ created: number; updated: number; skipped: number }>("/customers/import", { text });
      setResult(r);
      onDone();
    } catch (err) {
      notify(err instanceof ApiError ? err.message : c("error"), "error");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("importTitle")}>
      {result ? (
        <div className="flex flex-col gap-4">
          <div className="flex gap-2">
            <Stat n={result.created} label={t("imported")} tone="ok" />
            <Stat n={result.updated} label={t("updated")} tone="accent" />
            <Stat n={result.skipped} label={t("skipped")} tone="muted" />
          </div>
          <div className="flex justify-end">
            <Button type="button" onClick={onClose}>{c("close")}</Button>
          </div>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-muted">{t("importIntro")}</p>
          <textarea
            dir="ltr"
            rows={7}
            value={text}
            onChange={(e) => setText(e.target.value)}
            placeholder={"Sara, 0501234567\nMona, +966555000111, mona@email.com"}
            className="w-full rounded-xl border border-line bg-surface px-3 py-2.5 font-mono text-sm text-ink outline-none focus:border-accent"
          />
          <label className="cursor-pointer text-sm font-medium text-accent-ink hover:underline">
            {t("importFile")}
            <input type="file" accept=".csv,text/csv,text/plain" className="hidden" onChange={onFile} />
          </label>
          <p className="-mt-2 text-xs text-muted">{t("importHint")}</p>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
            <Button type="button" disabled={busy || !text.trim()} onClick={run}>{busy ? c("saving") : t("importRun")}</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function Stat({ n, label, tone }: { n: number; label: string; tone: "ok" | "accent" | "muted" }) {
  const color = tone === "ok" ? "text-[var(--color-ok)]" : tone === "accent" ? "text-accent-ink" : "text-muted";
  return (
    <div className="flex-1 rounded-xl border border-line bg-surface-2 p-3 text-center">
      <p className={`font-[family-name:var(--font-display)] text-2xl font-semibold tnum ${color}`}>{n}</p>
      <p className="text-xs text-muted">{label}</p>
    </div>
  );
}

type FullCustomer = Customer & { notes: string | null };

function CustomerDrawer({ customer, onClose, onSaved }: {
  customer: Customer; onClose: () => void; onSaved: () => void;
}) {
  const t = useTranslations("app.customers");
  const c = useTranslations("app.common");
  const td = useTranslations("app.dashboard");
  const locale = useLocale();
  const { salon } = useAuth();
  const { notify } = useToast();
  const tz = salon?.timezone;
  const [data, setData] = useState<{ history: HistoryItem[]; data: FullCustomer } | null>(null);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState({ name: customer.name, email: customer.email ?? "", notes: "" });
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    get<{ history: HistoryItem[]; data: FullCustomer }>(`/customers/${customer.id}`)
      .then((d) => {
        if (!live) return;
        setData(d);
        setForm({ name: d.data.name, email: d.data.email ?? "", notes: d.data.notes ?? "" });
      })
      .catch(() => {});
    return () => { live = false; };
  }, [customer.id]);

  const when = (iso: string) =>
    new Intl.DateTimeFormat(locale, { timeZone: tz, day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }).format(new Date(iso));
  const statusLabel = (s: string) =>
    ({ confirmed: td("statusConfirmed"), pending: td("statusPending"), done: td("statusDone"), no_show: td("statusNoShow"), cancelled: td("statusCancelled") } as Record<string, string>)[s] ?? s;
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setErr(null);
    setBusy(true);
    try {
      await patch(`/customers/${customer.id}`, {
        name: form.name, email: form.email || null, notes: form.notes || null,
      });
      setEditing(false);
      notify(c("saved"));
      onSaved();
    } catch (e2) {
      setErr(e2 instanceof ApiError ? e2.message : c("error"));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={form.name}>
      <div className="flex flex-col gap-4">
        {editing ? (
          <form onSubmit={save} className="flex flex-col gap-3">
            <Field label={t("name")}><Input required minLength={2} value={form.name} onChange={set("name")} /></Field>
            <Field label={t("phone")}>
              <Input dir="ltr" value={customer.phone} disabled className="opacity-70" />
            </Field>
            <Field label={t("email")}><Input type="email" dir="ltr" value={form.email} onChange={set("email")} /></Field>
            <Field label={t("notes")}><Input value={form.notes} onChange={set("notes")} /></Field>
            {err && <p className="rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">{err}</p>}
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setEditing(false)}>{c("cancel")}</Button>
              <Button type="submit" disabled={busy}>{busy ? c("saving") : c("save")}</Button>
            </div>
          </form>
        ) : (
          <div className="flex flex-wrap items-start gap-x-6 gap-y-1 text-sm">
            <span className="text-muted">{t("phone")}: <span className="text-ink" dir="ltr">{customer.phone}</span></span>
            {form.email && <span className="text-muted">{t("email")}: <span className="text-ink" dir="ltr">{form.email}</span></span>}
            <span className="text-muted">
              {t("lastVisit")}:{" "}
              <span className="text-ink">{customer.last_visit_at ? when(customer.last_visit_at) : t("never")}</span>
            </span>
            {form.notes && <span className="w-full text-muted">{t("notes")}: <span className="text-ink">{form.notes}</span></span>}
            <Button variant="ghost" className="ms-auto" onClick={() => setEditing(true)}>{c("edit")}</Button>
          </div>
        )}

        <div>
          <h3 className="mb-2 font-[family-name:var(--font-display)] text-base font-semibold text-ink">{t("history")}</h3>
          {!data ? <Spinner inline /> : data.history.length === 0 ? (
            <p className="text-sm text-muted">{t("noHistory")}</p>
          ) : (
            <ul className="divide-y divide-line">
              {data.history.map((h) => (
                <li key={h.id} className="flex items-center gap-3 py-2.5">
                  <span className="shrink-0 whitespace-nowrap font-mono text-xs text-accent-ink tnum" dir="ltr">{when(h.starts_at)}</span>
                  <span className="min-w-0 flex-1 truncate text-sm text-ink">{h.service?.name} · {h.staff?.name}</span>
                  <Badge tone={STATUS_TONE[h.status]}>{statusLabel(h.status)}</Badge>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </Modal>
  );
}
