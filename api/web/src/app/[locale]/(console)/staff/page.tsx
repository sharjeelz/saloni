"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { patch, post } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Modal";
import {
  Badge, Button, Card, EmptyState, Field, Input, LoadError, PageHeader, Spinner,
} from "@/components/ui/kit";

type Staff = {
  id: number; name: string; phone: string | null; email: string | null;
  title: string | null; role: string; is_active: boolean;
};

export default function StaffPage() {
  const t = useTranslations("app.staff");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const { data, loading, error, reload } = useApi<{ data: Staff[] }>("/staff");
  const [inviting, setInviting] = useState(false);

  if (loading) return <Spinner />;
  if (error || !data) return <LoadError onRetry={reload} />;

  async function deactivate(s: Staff) {
    if (!confirm(`${t("deactivate")} — ${s.name}?`)) return;
    try { await patch(`/staff/${s.id}/deactivate`); reload(); notify(c("saved")); }
    catch { notify(c("error"), "error"); }
  }

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")}>
        <Button onClick={() => setInviting(true)}>+ {t("invite")}</Button>
      </PageHeader>

      {data.data.length === 0 ? (
        <EmptyState message={t("empty")} action={<Button onClick={() => setInviting(true)}>+ {t("invite")}</Button>} />
      ) : (
        <div className="grid gap-3">
          {data.data.map((s) => (
            <Card key={s.id} className="flex flex-wrap items-center gap-3 p-5">
              <span className="grid size-10 shrink-0 place-items-center rounded-full bg-gold-soft font-semibold text-gold">
                {s.name.charAt(0)}
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-medium text-ink">{s.name}</p>
                <p className="text-sm text-muted" dir="ltr">
                  {[s.title, s.phone].filter(Boolean).join(" · ") || "—"}
                </p>
              </div>
              <Badge tone={s.is_active ? "accent" : "muted"}>
                {s.is_active ? t("active") : t("inactive")}
              </Badge>
              {s.is_active && (
                <Button variant="ghost" onClick={() => deactivate(s)}>{t("deactivate")}</Button>
              )}
            </Card>
          ))}
        </div>
      )}

      {inviting && (
        <InviteForm
          onClose={() => setInviting(false)}
          onSaved={() => { setInviting(false); reload(); notify(t("invited")); }}
        />
      )}
    </div>
  );
}

function InviteForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const t = useTranslations("app.staff");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const [form, setForm] = useState({ name: "", phone: "", email: "", title: "" });
  const [busy, setBusy] = useState(false);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      await post("/staff/invite", {
        name: form.name, phone: form.phone,
        email: form.email || undefined, title: form.title || undefined,
      });
      onSaved();
    } catch { notify(c("error"), "error"); setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} title={t("invite")}>
      <form onSubmit={save} className="flex flex-col gap-4">
        <Field label={t("name")}><Input required value={form.name} onChange={set("name")} /></Field>
        <Field label={t("phone")}><Input required dir="ltr" placeholder="+9665…" value={form.phone} onChange={set("phone")} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t("role")}><Input value={form.title} onChange={set("title")} /></Field>
          <Field label={t("email")}><Input type="email" dir="ltr" value={form.email} onChange={set("email")} /></Field>
        </div>
        <div className="mt-1 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
          <Button type="submit" disabled={busy}>{busy ? c("saving") : t("invite")}</Button>
        </div>
      </form>
    </Modal>
  );
}
