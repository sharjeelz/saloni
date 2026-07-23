"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ApiError, del, patch, put, upload } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useToast } from "@/components/ui/Toast";
import { useConfirm } from "@/components/ui/confirm";
import { Modal } from "@/components/ui/Modal";
import { Badge, Button, Card, EmptyState, Field, Input, LoadError, PageHeader, Spinner } from "@/components/ui/kit";

type Offer = { id: number; image_path: string; caption: string | null; link_url: string | null; is_active: boolean; sort_order: number };

export default function OffersPage() {
  const t = useTranslations("app.offers");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const confirm = useConfirm();
  const { data, loading, error, reload } = useApi<{ data: Offer[] }>("/offers");
  const [adding, setAdding] = useState(false);

  if (loading) return <Spinner />;
  if (error || !data) return <LoadError onRetry={reload} />;

  const offers = data.data;

  async function move(i: number, dir: -1 | 1) {
    const j = i + dir;
    if (j < 0 || j >= offers.length) return;
    const ids = offers.map((o) => o.id);
    [ids[i], ids[j]] = [ids[j], ids[i]];
    try { await put("/offers/reorder", { ids }); reload(); }
    catch { notify(c("error"), "error"); }
  }

  async function toggle(o: Offer) {
    try { await patch(`/offers/${o.id}`, { is_active: !o.is_active }); reload(); }
    catch { notify(c("error"), "error"); }
  }

  async function remove(o: Offer) {
    if (!(await confirm({ title: c("delete"), message: c("confirmDelete"), confirmLabel: c("delete") }))) return;
    try { await del(`/offers/${o.id}`); reload(); notify(c("deleted")); }
    catch { notify(c("error"), "error"); }
  }

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader title={t("title")}>
        <Button onClick={() => setAdding(true)}>+ {t("add")}</Button>
      </PageHeader>
      <p className="-mt-2 mb-5 text-sm text-muted">{t("intro")}</p>

      {offers.length === 0 ? (
        <EmptyState message={t("empty")} action={<Button onClick={() => setAdding(true)}>+ {t("add")}</Button>} />
      ) : (
        <div className="flex flex-col gap-3">
          {offers.map((o, i) => (
            <Card key={o.id} className="flex flex-wrap items-center gap-3 p-3">
              <span className="flex flex-col">
                <button onClick={() => move(i, -1)} disabled={i === 0} aria-label={c("moveUp")}
                  className="grid size-6 place-items-center text-xs text-muted hover:text-accent-ink disabled:opacity-30">▲</button>
                <button onClick={() => move(i, 1)} disabled={i === offers.length - 1} aria-label={c("moveDown")}
                  className="grid size-6 place-items-center text-xs text-muted hover:text-accent-ink disabled:opacity-30">▼</button>
              </span>
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={o.image_path} alt="" className="h-16 w-28 shrink-0 rounded-lg border border-line object-cover" />
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium text-ink">{o.caption || <span className="text-muted">{t("noCaption")}</span>}</p>
                {o.link_url && (
                  <a href={o.link_url} target="_blank" rel="noopener noreferrer" dir="ltr" className="block truncate text-xs text-accent-ink hover:underline">{o.link_url}</a>
                )}
              </div>
              <button onClick={() => toggle(o)} aria-label={o.is_active ? t("hide") : t("show")}>
                <Badge tone={o.is_active ? "ok" : "muted"}>{o.is_active ? t("active") : t("hidden")}</Badge>
              </button>
              <Button variant="danger" onClick={() => remove(o)}>{c("delete")}</Button>
            </Card>
          ))}
        </div>
      )}

      {adding && (
        <OfferForm
          onClose={() => setAdding(false)}
          onSaved={() => { setAdding(false); reload(); notify(t("added")); }}
        />
      )}
    </div>
  );
}

function OfferForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const t = useTranslations("app.offers");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [caption, setCaption] = useState("");
  const [link, setLink] = useState("");
  const [busy, setBusy] = useState(false);

  function onFile(e: React.ChangeEvent<HTMLInputElement>) {
    const f = e.target.files?.[0] ?? null;
    setFile(f);
    setPreview(f ? URL.createObjectURL(f) : null);
  }

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!file) return;
    setBusy(true);
    const fd = new FormData();
    fd.append("image", file);
    if (caption.trim()) fd.append("caption", caption.trim());
    if (link.trim()) fd.append("link_url", link.trim());
    try {
      await upload("/offers", fd);
      onSaved();
    } catch (err) {
      notify(err instanceof ApiError ? err.message : c("error"), "error");
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title={t("add")}>
      <form onSubmit={save} className="flex flex-col gap-4">
        <Field label={t("banner")}>
          <label className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-line bg-surface-2 p-4 text-center hover:border-accent">
            {preview ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={preview} alt="" className="max-h-40 w-full rounded-lg object-contain" />
            ) : (
              <>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="var(--color-muted)" strokeWidth="1.5"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="M21 15l-5-5L5 21" /></svg>
                <span className="text-sm text-muted">{t("chooseBanner")}</span>
              </>
            )}
            <input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={onFile} />
          </label>
        </Field>
        <Field label={t("caption")}>
          <Input value={caption} placeholder={t("captionPlaceholder")} onChange={(e) => setCaption(e.target.value)} />
        </Field>
        <Field label={t("link")}>
          <Input type="url" dir="ltr" placeholder="https://wa.me/966…" value={link} onChange={(e) => setLink(e.target.value)} />
        </Field>
        <p className="-mt-2 text-xs text-muted">{t("linkHint")}</p>
        <div className="mt-1 flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
          <Button type="submit" disabled={busy || !file}>{busy ? c("saving") : c("save")}</Button>
        </div>
      </form>
    </Modal>
  );
}
