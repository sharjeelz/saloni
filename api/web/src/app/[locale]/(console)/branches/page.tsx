"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { del, patch, post, put } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useToast } from "@/components/ui/Toast";
import { useConfirm } from "@/components/ui/confirm";
import { Modal } from "@/components/ui/Modal";
import {
  Button, Card, EmptyState, Field, Input, LoadError, PageHeader, Spinner,
} from "@/components/ui/kit";

type Branch = { id: number; name: string; address: string | null; city: string | null; maps_url: string | null; phone: string | null };
type StaffLite = { id: number; name: string; title: string | null };
type Hour = { weekday: number; start_time: string; end_time: string; user_id: number | null };

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

export default function BranchesPage() {
  const t = useTranslations("app.branches");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const { data, loading, error, reload } = useApi<{ data: Branch[] }>("/branches");

  const [editing, setEditing] = useState<Branch | null>(null);
  const [creating, setCreating] = useState(false);
  const [hoursFor, setHoursFor] = useState<Branch | null>(null);
  const [staffFor, setStaffFor] = useState<Branch | null>(null);

  if (loading) return <Spinner />;
  if (error || !data) return <LoadError onRetry={reload} />;

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")}>
        <Button onClick={() => setCreating(true)}>+ {t("add")}</Button>
      </PageHeader>

      {data.data.length === 0 ? (
        <EmptyState message={t("empty")} action={<Button onClick={() => setCreating(true)}>+ {t("add")}</Button>} />
      ) : (
        <div className="grid gap-3">
          {data.data.map((b) => (
            <Card key={b.id} className="flex flex-wrap items-center gap-3 p-5">
              <div className="min-w-0 flex-1">
                <p className="font-medium text-ink">{b.name}</p>
                <p className="text-sm text-muted">
                  {[b.address, b.city].filter(Boolean).join("، ") || "—"}
                </p>
                {b.maps_url && (
                  <a href={b.maps_url} target="_blank" rel="noopener noreferrer"
                    className="mt-0.5 inline-flex items-center gap-1 text-sm font-medium text-accent-ink hover:underline">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 21s-7-5.686-7-11a7 7 0 1114 0c0 5.314-7 11-7 11z" /><circle cx="12" cy="10" r="2.5" /></svg>
                    {t("viewMap")}
                  </a>
                )}
              </div>
              <div className="flex flex-wrap gap-2">
                <Button variant="ghost" onClick={() => setHoursFor(b)}>{t("manageHours")}</Button>
                <Button variant="ghost" onClick={() => setStaffFor(b)}>{t("assignStaff")}</Button>
                <Button variant="ghost" onClick={() => setEditing(b)}>{c("edit")}</Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {(creating || editing) && (
        <BranchForm
          branch={editing}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSaved={() => { setCreating(false); setEditing(null); reload(); notify(c("saved")); }}
          onDeleted={() => { setEditing(null); reload(); notify(c("deleted")); }}
        />
      )}
      {hoursFor && <HoursModal branch={hoursFor} onClose={() => setHoursFor(null)} onSaved={() => { setHoursFor(null); notify(c("saved")); }} />}
      {staffFor && <StaffModal branch={staffFor} onClose={() => setStaffFor(null)} onSaved={() => { setStaffFor(null); notify(c("saved")); }} />}
    </div>
  );
}

function BranchForm({ branch, onClose, onSaved, onDeleted }: {
  branch: Branch | null; onClose: () => void; onSaved: () => void; onDeleted: () => void;
}) {
  const t = useTranslations("app.branches");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const confirm = useConfirm();
  const [form, setForm] = useState({
    name: branch?.name ?? "", city: branch?.city ?? "", address: branch?.address ?? "",
    maps_url: branch?.maps_url ?? "", phone: branch?.phone ?? "",
  });
  const [busy, setBusy] = useState(false);
  const set = (k: string) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    const payload = { ...form, maps_url: form.maps_url.trim() || null };
    try {
      if (branch) await patch(`/branches/${branch.id}`, payload);
      else await post("/branches", payload);
      onSaved();
    } catch { notify(c("error"), "error"); setBusy(false); }
  }
  async function remove() {
    if (!branch || !(await confirm({ title: c("delete"), message: c("confirmDelete"), confirmLabel: c("delete") }))) return;
    try { await del(`/branches/${branch.id}`); onDeleted(); } catch { notify(c("error"), "error"); }
  }

  return (
    <Modal open onClose={onClose} title={branch ? t("edit") : t("add")}>
      <form onSubmit={save} className="flex flex-col gap-4">
        <Field label={t("name")}><Input required value={form.name} onChange={set("name")} /></Field>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label={t("city")}><Input value={form.city} onChange={set("city")} /></Field>
          <Field label={t("phone")}><Input dir="ltr" value={form.phone} onChange={set("phone")} /></Field>
        </div>
        <Field label={t("address")}>
          <Input value={form.address} placeholder={t("addressPlaceholder")} onChange={set("address")} />
        </Field>
        <Field label={t("mapsUrl")}>
          <Input type="url" dir="ltr" value={form.maps_url} placeholder="https://maps.google.com/…" onChange={set("maps_url")} />
        </Field>
        <p className="-mt-2 text-xs text-muted">{t("mapsUrlHint")}</p>
        <div className="mt-1 flex items-center justify-between gap-2">
          {branch ? <Button type="button" variant="danger" onClick={remove}>{c("delete")}</Button> : <span />}
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
            <Button type="submit" disabled={busy}>{busy ? c("saving") : c("save")}</Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}

function HoursModal({ branch, onClose, onSaved }: { branch: Branch; onClose: () => void; onSaved: () => void }) {
  const t = useTranslations("app.branches");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const { data, loading, error, reload } = useApi<{ data: Hour[] }>(`/branches/${branch.id}/hours`);
  const [rows, setRows] = useState<Record<number, { open: boolean; start: string; end: string }> | null>(null);
  const [busy, setBusy] = useState(false);

  // Seed from the loaded hours (branch-level rows only).
  useEffect(() => {
    if (!data) return;
    const next: Record<number, { open: boolean; start: string; end: string }> = {};
    for (let d = 0; d < 7; d++) {
      const h = data.data.find((x) => x.weekday === d && x.user_id === null);
      next[d] = h
        ? { open: true, start: h.start_time.slice(0, 5), end: h.end_time.slice(0, 5) }
        : { open: false, start: "10:00", end: "22:00" };
    }
    setRows(next);
  }, [data]);

  async function save() {
    if (!rows) return;
    setBusy(true);
    const hours = Object.entries(rows)
      .filter(([, v]) => v.open)
      .map(([d, v]) => ({ weekday: Number(d), start_time: v.start, end_time: v.end }));
    try { await put(`/branches/${branch.id}/hours`, { hours }); onSaved(); }
    catch { notify(c("error"), "error"); setBusy(false); }
  }

  return (
    <Modal open onClose={onClose} title={`${t("hours")} · ${branch.name}`}>
      {error ? <LoadError onRetry={reload} /> : loading || !rows ? <Spinner inline /> : (
        <div className="flex flex-col gap-2">
          {WEEKDAYS.map((label, d) => {
            const row = rows[d] ?? { open: false, start: "10:00", end: "22:00" };
            return (
              <div key={d} className="flex items-center gap-3">
                <label className="flex w-20 shrink-0 items-center gap-2 text-sm text-ink">
                  <input type="checkbox" checked={row.open}
                    onChange={(e) => setRows((r) => ({ ...r, [d]: { ...row, open: e.target.checked } }))} />
                  {label}
                </label>
                {row.open ? (
                  <div className="flex min-w-0 flex-1 items-center gap-2" dir="ltr">
                    <Input type="time" value={row.start} onChange={(e) => setRows((r) => ({ ...r, [d]: { ...row, start: e.target.value } }))} />
                    <span className="text-muted">–</span>
                    <Input type="time" value={row.end} onChange={(e) => setRows((r) => ({ ...r, [d]: { ...row, end: e.target.value } }))} />
                  </div>
                ) : <span className="text-sm text-muted">{t("closed")}</span>}
              </div>
            );
          })}
          <div className="mt-3 flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{c("cancel")}</Button>
            <Button onClick={save} disabled={busy}>{busy ? c("saving") : c("save")}</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function StaffModal({ branch, onClose, onSaved }: { branch: Branch; onClose: () => void; onSaved: () => void }) {
  const t = useTranslations("app.branches");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const allStaff = useApi<{ data: StaffLite[] }>("/staff");
  const detail = useApi<{ data: { staff: StaffLite[] } }>(`/branches/${branch.id}`);
  const [selected, setSelected] = useState<Set<number> | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (detail.data) setSelected(new Set(detail.data.data.staff.map((s) => s.id)));
  }, [detail.data]);

  async function save() {
    if (!selected) return;
    setBusy(true);
    try { await put(`/branches/${branch.id}/staff`, { staff_ids: [...selected] }); onSaved(); }
    catch { notify(c("error"), "error"); setBusy(false); }
  }

  const toggle = (id: number) => setSelected((s) => {
    const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n;
  });

  const staffList = allStaff.data?.data ?? [];
  const failed = allStaff.error || detail.error;
  const ready = !allStaff.loading && !detail.loading && selected !== null;

  return (
    <Modal open onClose={onClose} title={`${t("staff")} · ${branch.name}`}>
      {failed ? (
        <LoadError onRetry={() => { allStaff.reload(); detail.reload(); }} />
      ) : !ready ? (
        <Spinner inline />
      ) : staffList.length === 0 ? (
        <p className="py-4 text-center text-sm text-muted">{t("noStaff")}</p>
      ) : (
        <div className="flex flex-col gap-1.5">
          {staffList.map((s) => (
            <label key={s.id} className="flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-surface-2">
              <input type="checkbox" checked={selected!.has(s.id)} onChange={() => toggle(s.id)} />
              <span className="text-ink">{s.name}</span>
              {s.title && <span className="text-sm text-muted">{s.title}</span>}
            </label>
          ))}
          <div className="mt-3 flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{c("cancel")}</Button>
            <Button onClick={save} disabled={busy}>{busy ? c("saving") : c("save")}</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
