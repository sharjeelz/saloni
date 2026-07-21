"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ApiError, patch, post } from "@/lib/api";
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
  const [editing, setEditing] = useState<Staff | null>(null);

  if (loading) return <Spinner />;
  if (error || !data) return <LoadError onRetry={reload} />;

  async function setActive(s: Staff, active: boolean) {
    const verb = active ? t("reactivate") : t("deactivate");
    if (!confirm(`${verb} — ${s.name}?`)) return;
    try {
      await patch(`/staff/${s.id}/${active ? "activate" : "deactivate"}`);
      reload();
      notify(c("saved"));
    } catch { notify(c("error"), "error"); }
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
              <Button variant="ghost" onClick={() => setEditing(s)}>{c("edit")}</Button>
              {s.is_active ? (
                <Button variant="ghost" onClick={() => setActive(s, false)}>{t("deactivate")}</Button>
              ) : (
                <Button variant="ghost" onClick={() => setActive(s, true)}>{t("reactivate")}</Button>
              )}
            </Card>
          ))}
        </div>
      )}

      {(inviting || editing) && (
        <StaffForm
          staff={editing}
          onClose={() => { setInviting(false); setEditing(null); }}
          onSaved={() => {
            const wasEditing = !!editing;
            setInviting(false); setEditing(null); reload();
            notify(wasEditing ? c("saved") : t("invited"));
          }}
        />
      )}
    </div>
  );
}

function StaffForm({ staff, onClose, onSaved }: {
  staff: Staff | null; onClose: () => void; onSaved: () => void;
}) {
  const t = useTranslations("app.staff");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const [form, setForm] = useState({
    name: staff?.name ?? "", phone: staff?.phone ?? "",
    email: staff?.email ?? "", title: staff?.title ?? "",
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    const payload = {
      name: form.name, phone: form.phone,
      email: form.email || undefined, title: form.title || undefined,
    };
    try {
      if (staff) await patch(`/staff/${staff.id}`, payload);
      else await post("/staff/invite", payload);
      onSaved();
    } catch (err) {
      // Surface the backend's validation message (e.g. bad phone).
      setError(err instanceof ApiError ? err.message : c("error"));
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={staff ? c("edit") : t("invite")}>
      <form onSubmit={save} className="flex flex-col gap-4">
        <Field label={t("name")}><Input required minLength={2} value={form.name} onChange={set("name")} /></Field>
        <Field label={t("phone")}>
          <Input required type="tel" inputMode="tel" dir="ltr" placeholder="+9665…" value={form.phone} onChange={set("phone")} />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t("role")}><Input value={form.title} onChange={set("title")} /></Field>
          <Field label={t("email")}><Input type="email" dir="ltr" value={form.email} onChange={set("email")} /></Field>
        </div>
        {error && <p className="rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">{error}</p>}
        <div className="mt-1 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
          <Button type="submit" disabled={busy}>{busy ? c("saving") : staff ? c("save") : t("invite")}</Button>
        </div>
      </form>
    </Modal>
  );
}
