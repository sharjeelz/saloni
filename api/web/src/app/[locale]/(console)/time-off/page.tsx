"use client";

import { useMemo, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { del, post } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useAuth } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Modal";
import {
  Badge, Button, Card, EmptyState, Field, Input, LoadError, PageHeader, Select, Spinner,
} from "@/components/ui/kit";

type Block = {
  id: number; branch_id: number; user_id: number | null;
  starts_at: string; ends_at: string; reason: string | null;
};
type Named = { id: number; name: string };

export default function TimeOffPage() {
  const t = useTranslations("app.timeoff");
  const c = useTranslations("app.common");
  const locale = useLocale();
  const { salon } = useAuth();
  const tz = salon?.timezone;
  const { notify } = useToast();
  const blocks = useApi<{ data: Block[] }>("/time-off");
  const branches = useApi<{ data: Named[] }>("/branches");
  const staff = useApi<{ data: Named[] }>("/staff");
  const [adding, setAdding] = useState(false);

  const branchName = useMemo(
    () => Object.fromEntries((branches.data?.data ?? []).map((b) => [b.id, b.name])),
    [branches.data],
  );
  const staffName = useMemo(
    () => Object.fromEntries((staff.data?.data ?? []).map((s) => [s.id, s.name])),
    [staff.data],
  );

  if (blocks.loading || branches.loading || staff.loading) return <Spinner />;
  if (blocks.error || !blocks.data) return <LoadError onRetry={blocks.reload} />;

  const day = (iso: string) =>
    new Intl.DateTimeFormat(locale, { timeZone: tz, day: "numeric", month: "short", year: "numeric" }).format(new Date(iso));

  async function remove(b: Block) {
    if (!confirm(c("confirmDelete"))) return;
    try { await del(`/time-off/${b.id}`); blocks.reload(); notify(c("deleted")); }
    catch { notify(c("error"), "error"); }
  }

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")}>
        <Button onClick={() => setAdding(true)}>+ {t("add")}</Button>
      </PageHeader>

      {blocks.data.data.length === 0 ? (
        <EmptyState message={t("empty")} action={<Button onClick={() => setAdding(true)}>+ {t("add")}</Button>} />
      ) : (
        <div className="grid gap-3">
          {blocks.data.data.map((b) => (
            <Card key={b.id} className="flex flex-wrap items-center gap-3 p-5">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-ink">{branchName[b.branch_id] ?? "—"}</span>
                  {b.user_id ? (
                    <Badge tone="accent">{staffName[b.user_id] ?? "—"}</Badge>
                  ) : (
                    <Badge tone="warn">{t("closure")}</Badge>
                  )}
                </div>
                <p className="text-sm text-muted" dir="ltr">
                  {day(b.starts_at)} – {day(b.ends_at)}{b.reason ? ` · ${b.reason}` : ""}
                </p>
              </div>
              <Button variant="danger" onClick={() => remove(b)}>{c("delete")}</Button>
            </Card>
          ))}
        </div>
      )}

      {adding && (
        <BlockForm
          branches={branches.data?.data ?? []}
          staff={staff.data?.data ?? []}
          onClose={() => setAdding(false)}
          onSaved={() => { setAdding(false); blocks.reload(); notify(t("added")); }}
        />
      )}
    </div>
  );
}

function BlockForm({ branches, staff, onClose, onSaved }: {
  branches: Named[]; staff: Named[]; onClose: () => void; onSaved: () => void;
}) {
  const t = useTranslations("app.timeoff");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const [form, setForm] = useState({ branch_id: "", who: "", from: "", to: "", reason: "" });
  const [busy, setBusy] = useState(false);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => ({ ...f, [k]: e.target.value }));

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      await post("/time-off", {
        branch_id: Number(form.branch_id),
        user_id: form.who ? Number(form.who) : null,
        starts_at: `${form.from} 00:00:00`,
        ends_at: `${form.to} 23:59:59`,
        reason: form.reason || null,
      });
      onSaved();
    } catch { notify(c("error"), "error"); setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} title={t("newBlock")}>
      <form onSubmit={save} className="flex flex-col gap-4">
        <Field label={t("branch")}>
          <Select required value={form.branch_id} onChange={set("branch_id")}>
            <option value="" disabled>—</option>
            {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </Select>
        </Field>
        <Field label={t("who")}>
          <Select value={form.who} onChange={set("who")}>
            <option value="">{t("wholeBranch")}</option>
            {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
        </Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" dir="ltr">
          <Field label={t("from")}><Input type="date" required value={form.from} onChange={set("from")} /></Field>
          <Field label={t("to")}><Input type="date" required value={form.to} onChange={set("to")} /></Field>
        </div>
        <Field label={t("reason")}><Input value={form.reason} onChange={set("reason")} /></Field>
        <div className="mt-1 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
          <Button type="submit" disabled={busy}>{busy ? c("saving") : c("save")}</Button>
        </div>
      </form>
    </Modal>
  );
}
