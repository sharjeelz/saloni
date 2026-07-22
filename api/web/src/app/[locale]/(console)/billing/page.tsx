"use client";

import { useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { post } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useToast } from "@/components/ui/Toast";
import { Badge, Button, Card, LoadError, PageHeader, Spinner } from "@/components/ui/kit";

type Status = {
  plan: string;
  trial_ends_at: string | null;
  on_trial: boolean;
  subscription: { plan: string; status: string; current_period_end: string | null } | null;
};
type Plan = {
  key: string; name: string; price: number; vat: number; total: number;
  currency: string; interval: string; features: string[];
};
type Invoice = { id: number; number: string; total: string; currency: string; issued_at: string; status: string };

const INVOICE_TONE: Record<string, string> = { paid: "accent", unpaid: "warn", void: "muted" };

export default function BillingPage() {
  const t = useTranslations("app.billing");
  const c = useTranslations("app.common");
  const locale = useLocale();
  const { notify } = useToast();
  const status = useApi<Status>("/billing");
  const plans = useApi<{ data: Plan[] }>("/billing/plans");
  const invoices = useApi<{ data: Invoice[] }>("/billing/invoices");
  const [busy, setBusy] = useState<string | null>(null);

  if (status.loading || plans.loading) return <Spinner />;
  if (status.error || !status.data || !plans.data) return <LoadError onRetry={status.reload} />;

  const money = (n: number, cur: string) =>
    new Intl.NumberFormat(locale, { style: "currency", currency: cur, maximumFractionDigits: 2 }).format(n);
  const date = (iso: string) =>
    new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", year: "numeric" }).format(new Date(iso));

  const sub = status.data.subscription;
  const activeKey = sub?.status === "active" ? sub.plan : null;

  async function subscribe(key: string) {
    setBusy(key);
    try {
      await post("/billing/subscribe", { plan: key });
      notify(t("subscribed"));
      status.reload();
      invoices.reload();
    } catch { notify(c("error"), "error"); }
    finally { setBusy(null); }
  }

  async function cancel() {
    if (!confirm(t("confirmCancel"))) return;
    setBusy("__cancel");
    try {
      await post("/billing/cancel");
      notify(t("canceled"));
      status.reload();
    } catch { notify(c("error"), "error"); }
    finally { setBusy(null); }
  }

  const invoiceLabel = (s: string) =>
    ({ paid: t("paid"), unpaid: t("unpaid"), void: t("void") } as Record<string, string>)[s] ?? s;

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")} />

      {/* Current status */}
      <Card className="mb-6 flex flex-wrap items-center justify-between gap-3 p-6">
        <div>
          <p className="text-sm text-muted">{t("currentPlan")}</p>
          <p className="mt-0.5 font-[family-name:var(--font-display)] text-2xl font-semibold text-ink capitalize">
            {activeKey ?? (status.data.on_trial ? t("onTrial") : status.data.plan)}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {status.data.on_trial && status.data.trial_ends_at && (
            <Badge tone="gold">{t("trialEnds", { date: date(status.data.trial_ends_at) })}</Badge>
          )}
          {sub?.status === "active" && sub.current_period_end && (
            <Badge tone="accent">{t("renews", { date: date(sub.current_period_end) })}</Badge>
          )}
          {sub?.status === "canceled" && sub.current_period_end && (
            <Badge tone="muted">{t("endsOn", { date: date(sub.current_period_end) })}</Badge>
          )}
          {activeKey && (
            <Button variant="danger" disabled={busy === "__cancel"} onClick={cancel}>
              {t("cancel")}
            </Button>
          )}
        </div>
      </Card>

      {/* Plans */}
      <h2 className="mb-3 font-[family-name:var(--font-display)] text-lg font-semibold text-ink">{t("choosePlan")}</h2>
      <div className="grid gap-4 sm:grid-cols-2">
        {plans.data.data.map((p) => {
          const active = activeKey === p.key;
          return (
            <Card key={p.key} className={`flex flex-col p-6 ${active ? "ring-2 ring-[color:var(--color-accent)]" : ""}`}>
              <div className="flex items-baseline justify-between">
                <h3 className="font-[family-name:var(--font-display)] text-xl font-semibold text-ink">{p.name}</h3>
                {active && <Badge tone="accent">{t("paid")}</Badge>}
              </div>
              <p className="mt-2">
                <span className="font-[family-name:var(--font-display)] text-3xl font-semibold text-gold tnum">
                  {money(p.total, p.currency)}
                </span>
                <span className="text-sm text-muted"> {t("perMonth")} · {t("vatIncl")}</span>
              </p>
              <ul className="mt-4 flex flex-1 flex-col gap-2 text-sm text-muted">
                {p.features.map((f) => (
                  <li key={f} className="flex items-center gap-2">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" strokeWidth="2.5"><path d="M5 12l5 5L20 6" /></svg>
                    {f}
                  </li>
                ))}
              </ul>
              <Button className="mt-5" disabled={active || busy === p.key} onClick={() => subscribe(p.key)}>
                {busy === p.key ? c("saving") : active ? t("paid") : t("subscribe")}
              </Button>
            </Card>
          );
        })}
      </div>

      {/* Invoices */}
      <h2 className="mb-3 mt-8 font-[family-name:var(--font-display)] text-lg font-semibold text-ink">{t("invoices")}</h2>
      <Card className="p-2">
        {invoices.loading ? (
          <Spinner inline />
        ) : !invoices.data || invoices.data.data.length === 0 ? (
          <p className="p-6 text-center text-sm text-muted">{t("noInvoices")}</p>
        ) : (
          <ul className="divide-y divide-line">
            {invoices.data.data.map((inv) => (
              <li key={inv.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                <span className="min-w-0 truncate font-mono text-sm text-ink" dir="ltr">{inv.number}</span>
                <span className="text-sm text-muted">{date(inv.issued_at)}</span>
                <span className="ms-auto font-medium text-ink tnum">{money(Number(inv.total), inv.currency)}</span>
                <Badge tone={INVOICE_TONE[inv.status] ?? "muted"}>{invoiceLabel(inv.status)}</Badge>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
