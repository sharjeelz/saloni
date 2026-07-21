"use client";

import { useEffect, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { get } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useAuth } from "@/lib/auth";
import { Modal } from "@/components/ui/Modal";
import { Badge, Card, EmptyState, Input, LoadError, PageHeader, Spinner } from "@/components/ui/kit";

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
      <PageHeader title={t("title")} />

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

      {open && <CustomerDrawer customer={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

function CustomerDrawer({ customer, onClose }: { customer: Customer; onClose: () => void }) {
  const t = useTranslations("app.customers");
  const td = useTranslations("app.dashboard");
  const locale = useLocale();
  const { salon } = useAuth();
  const tz = salon?.timezone;
  const [data, setData] = useState<{ history: HistoryItem[]; data: Customer } | null>(null);

  useEffect(() => {
    let live = true;
    get<{ history: HistoryItem[]; data: Customer }>(`/customers/${customer.id}`)
      .then((d) => live && setData(d))
      .catch(() => {});
    return () => { live = false; };
  }, [customer.id]);

  const when = (iso: string) =>
    new Intl.DateTimeFormat(locale, { timeZone: tz, day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }).format(new Date(iso));
  const statusLabel = (s: string) =>
    ({ confirmed: td("statusConfirmed"), pending: td("statusPending"), done: td("statusDone"), no_show: td("statusNoShow"), cancelled: td("statusCancelled") } as Record<string, string>)[s] ?? s;

  return (
    <Modal open onClose={onClose} title={customer.name}>
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
          <span className="text-muted">{t("phone")}: <span className="text-ink" dir="ltr">{customer.phone}</span></span>
          {customer.email && <span className="text-muted">{t("email")}: <span className="text-ink" dir="ltr">{customer.email}</span></span>}
          <span className="text-muted">
            {t("lastVisit")}:{" "}
            <span className="text-ink">{customer.last_visit_at ? when(customer.last_visit_at) : t("never")}</span>
          </span>
        </div>

        <div>
          <h3 className="mb-2 font-[family-name:var(--font-display)] text-base font-semibold text-ink">{t("history")}</h3>
          {!data ? <Spinner /> : data.history.length === 0 ? (
            <p className="text-sm text-muted">{t("noHistory")}</p>
          ) : (
            <ul className="divide-y divide-line">
              {data.history.map((h) => (
                <li key={h.id} className="flex items-center gap-3 py-2.5">
                  <span className="w-24 shrink-0 font-mono text-xs text-accent-ink tnum" dir="ltr">{when(h.starts_at)}</span>
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
