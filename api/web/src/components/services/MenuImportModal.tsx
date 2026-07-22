"use client";

import { useEffect, useRef, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { ApiError, get, post, upload } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Modal";
import { Button, Input, Spinner } from "@/components/ui/kit";

type Row = { name: string; name_en: string | null; duration_min: number; price: number; category: string | null; category_key: string | null };
type StaffLite = { id: number; name: string; is_active?: boolean };
type Preset = { key: string; ar: string; en: string };

/**
 * Onboarding shortcut: the owner uploads a photo of their price list, Claude
 * extracts the services, and they review/edit before anything is saved.
 * Turns a long data-entry form into a five-minute favour.
 */
export function MenuImportModal({ onClose, onImported }: { onClose: () => void; onImported: () => void }) {
  const t = useTranslations("app.services");
  const c = useTranslations("app.common");
  const locale = useLocale();
  const { notify } = useToast();
  const [presets, setPresets] = useState<Preset[]>([]);
  const [rows, setRows] = useState<Row[] | null>(null);
  const [scanning, setScanning] = useState(false);
  const [saving, setSaving] = useState(false);
  const [staff, setStaff] = useState<StaffLite[]>([]);
  const [staffIds, setStaffIds] = useState<number[]>([]);
  const fileRef = useRef<HTMLInputElement>(null);

  // Load the team so imported services can be assigned up front (default: all).
  useEffect(() => {
    get<{ data: StaffLite[] }>("/staff")
      .then((r) => {
        const active = r.data.filter((s) => s.is_active !== false);
        setStaff(active);
        setStaffIds(active.map((s) => s.id));
      })
      .catch(() => {});
  }, []);

  useEffect(() => {
    get<{ data: Preset[] }>("/service-category-presets").then((r) => setPresets(r.data)).catch(() => {});
  }, []);

  const toggleStaff = (id: number) =>
    setStaffIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));

  // Show the canonical category a service was mapped to, in the current language.
  const catLabel = (row: Row) => {
    if (row.category_key) {
      const p = presets.find((x) => x.key === row.category_key);
      if (p) return locale === "ar" ? p.ar : p.en;
    }
    return row.category ?? "";
  };

  async function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const files = e.target.files;
    if (!files || files.length === 0) return;
    setScanning(true);
    try {
      const fd = new FormData();
      Array.from(files).forEach((f) => fd.append("menu[]", f));
      const res = await upload<{ services: Row[] }>("/services/scan-menu", fd);
      setRows(res.services);
      if (res.services.length === 0) notify(t("importEmpty"), "error");
    } catch (err) {
      notify(err instanceof ApiError ? err.message : c("error"), "error");
    } finally {
      setScanning(false);
      e.target.value = "";
    }
  }

  const update = (i: number, patch: Partial<Row>) =>
    setRows((r) => (r ? r.map((row, idx) => (idx === i ? { ...row, ...patch } : row)) : r));
  const remove = (i: number) => setRows((r) => (r ? r.filter((_, idx) => idx !== i) : r));

  async function save() {
    if (!rows || rows.length === 0) return;
    setSaving(true);
    try {
      const res = await post<{ created: number }>("/services/import", { services: rows, staff_ids: staffIds });
      notify(t("importDone", { n: res.created }));
      onImported();
      onClose();
    } catch (err) {
      notify(err instanceof ApiError ? err.message : c("error"), "error");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("importTitle")}>
      {rows === null ? (
        <div className="flex flex-col items-center gap-4 py-6 text-center">
          {scanning ? (
            <>
              <Spinner inline />
              <p className="text-sm text-muted">{t("scanning")}</p>
            </>
          ) : (
            <>
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="var(--color-muted)" strokeWidth="1.4">
                <rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="M21 15l-5-5L5 21" />
              </svg>
              <p className="max-w-xs text-sm text-muted">{t("importIntro")}</p>
              <p className="max-w-xs text-xs text-muted">{t("importBilingual")}</p>
              <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" multiple className="hidden" onChange={onFile} />
              <Button type="button" onClick={() => fileRef.current?.click()}>{t("importChoose")}</Button>
            </>
          )}
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          <p className="text-sm text-muted">{t("importFound", { n: rows.length })}</p>

          {staff.length === 0 && (
            <p className="rounded-xl border border-line bg-surface-2 p-3 text-sm text-muted">{t("importNoStaff")}</p>
          )}

          {staff.length > 0 && (
            <div className="rounded-xl border border-line bg-surface-2 p-3">
              <p className="mb-2 text-sm font-medium text-ink">{t("importAssign")}</p>
              <div className="flex flex-wrap gap-2">
                {staff.map((m) => {
                  const on = staffIds.includes(m.id);
                  return (
                    <button
                      key={m.id}
                      type="button"
                      onClick={() => toggleStaff(m.id)}
                      aria-pressed={on}
                      className={`rounded-full border px-3 py-1.5 text-sm font-medium transition-colors ${
                        on ? "border-accent bg-accent text-on-accent" : "border-line text-ink hover:border-accent"
                      }`}
                    >
                      {m.name}
                    </button>
                  );
                })}
              </div>
              <p className="mt-2 text-xs text-muted">{t("importAssignHint")}</p>
            </div>
          )}

          <div className="flex flex-col gap-2.5">
            {rows.map((row, i) => (
              <div key={i} className="rounded-xl border border-line bg-surface-2 p-3">
                <div className="mb-2 flex items-center gap-2">
                  <Input value={row.name} onChange={(e) => update(i, { name: e.target.value })} className="flex-1" />
                  <button
                    onClick={() => remove(i)}
                    aria-label={c("delete")}
                    className="grid size-8 shrink-0 place-items-center rounded-full text-muted hover:bg-crit/10 hover:text-crit"
                  >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6 6 18M6 6l12 12" /></svg>
                  </button>
                </div>
                <Input
                  dir="ltr"
                  value={row.name_en ?? ""}
                  placeholder={t("nameEnPlaceholder")}
                  onChange={(e) => update(i, { name_en: e.target.value || null })}
                  className="mb-2"
                />
                <div className="grid grid-cols-3 gap-2">
                  <label className="flex flex-col gap-1">
                    <span className="text-xs text-muted">{t("duration")}</span>
                    <Input type="number" dir="ltr" min={5} value={row.duration_min}
                      onChange={(e) => update(i, { duration_min: Number(e.target.value) })} />
                  </label>
                  <label className="flex flex-col gap-1">
                    <span className="text-xs text-muted">{t("price")}</span>
                    <Input type="number" dir="ltr" min={0} value={row.price}
                      onChange={(e) => update(i, { price: Number(e.target.value) })} />
                  </label>
                  <label className="flex flex-col gap-1">
                    <span className="text-xs text-muted">{t("category")}</span>
                    <Input value={catLabel(row)} placeholder={t("noCategory")}
                      onChange={(e) => update(i, { category: e.target.value || null, category_key: null })} />
                  </label>
                </div>
              </div>
            ))}
          </div>
          <div className="mt-1 flex items-center justify-between gap-2">
            <Button type="button" variant="ghost" onClick={() => setRows(null)}>{t("importAnother")}</Button>
            <Button type="button" onClick={save} disabled={saving || rows.length === 0}>
              {saving ? c("saving") : t("importAdd", { n: rows.length })}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
