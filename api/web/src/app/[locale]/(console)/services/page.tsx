"use client";

import { useEffect, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { del, patch, post, put } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useToast } from "@/components/ui/Toast";
import { Modal } from "@/components/ui/Modal";
import { MenuImportModal } from "@/components/services/MenuImportModal";
import {
  Badge, Button, Card, EmptyState, Field, Input, LoadError, PageHeader, Select, Spinner,
} from "@/components/ui/kit";

type Category = { id: number; name: string; services_count?: number };
type StaffLite = { id: number; name: string; title: string | null };
type Service = {
  id: number; name: string; duration_min: number; price: string;
  currency: string; is_active: boolean; service_category_id: number | null;
  category?: { id: number; name: string } | null;
  staff?: StaffLite[];
};

export default function ServicesPage() {
  const t = useTranslations("app.services");
  const c = useTranslations("app.common");
  const locale = useLocale();
  const { notify } = useToast();
  const services = useApi<{ data: Service[] }>("/services");
  const categories = useApi<{ data: Category[] }>("/service-categories");

  const [editing, setEditing] = useState<Service | null>(null);
  const [creating, setCreating] = useState(false);
  const [editCat, setEditCat] = useState<Category | null>(null);
  const [addingCat, setAddingCat] = useState(false);
  const [staffFor, setStaffFor] = useState<Service | null>(null);
  const [importing, setImporting] = useState(false);

  if (services.loading || categories.loading) return <Spinner />;
  if (services.error || !services.data || !categories.data) return <LoadError onRetry={services.reload} />;

  const money = (p: string, cur: string) =>
    new Intl.NumberFormat(locale, { style: "currency", currency: cur, minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(p));

  const reloadCats = () => { categories.reload(); services.reload(); };

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title={t("title")}>
        <Button variant="ghost" onClick={() => setImporting(true)}>{t("importMenu")}</Button>
        <Button onClick={() => setCreating(true)}>+ {t("add")}</Button>
      </PageHeader>

      {/* Categories management */}
      <Card className="mb-6 p-5">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-[family-name:var(--font-display)] text-lg font-semibold text-ink">
            {t("manageCategories")}
          </h2>
          <Button variant="ghost" onClick={() => setAddingCat(true)}>+ {t("addCategory")}</Button>
        </div>
        {categories.data.data.length === 0 ? (
          <p className="text-sm text-muted">{t("noCategories")}</p>
        ) : (
          <ul className="flex flex-col divide-y divide-line">
            {categories.data.data.map((cat) => (
              <li key={cat.id} className="flex items-center gap-3 py-2.5">
                <span className="font-medium text-ink">{cat.name}</span>
                <span className="text-sm text-muted">
                  {t("servicesCount", { n: cat.services_count ?? 0 })}
                </span>
                <button
                  onClick={() => setEditCat(cat)}
                  className="ms-auto text-sm font-medium text-muted hover:text-accent-ink"
                >
                  {c("edit")}
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* Services */}
      {services.data.data.length === 0 ? (
        <EmptyState message={t("empty")} action={<Button onClick={() => setCreating(true)}>+ {t("add")}</Button>} />
      ) : (
        <div className="grid gap-3">
          {services.data.data.map((s) => (
            <Card key={s.id} className="flex flex-wrap items-center gap-3 p-5">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <p className="font-medium text-ink">{s.name}</p>
                  {s.category && <Badge tone="gold">{s.category.name}</Badge>}
                </div>
                <p className="text-sm text-muted">
                  {s.duration_min} {t("min")} · {money(s.price, s.currency)}
                </p>
                <p className="mt-0.5 text-sm text-muted">
                  {t("performedBy")}:{" "}
                  <span className="text-ink">
                    {s.staff && s.staff.length > 0 ? s.staff.map((m) => m.name).join(locale === "ar" ? "، " : ", ") : t("noneYet")}
                  </span>
                </p>
              </div>
              <Button variant="ghost" onClick={() => setStaffFor(s)}>{t("assignStaff")}</Button>
              <Button variant="ghost" onClick={() => setEditing(s)}>{c("edit")}</Button>
            </Card>
          ))}
        </div>
      )}

      {(creating || editing) && (
        <ServiceForm
          service={editing} categories={categories.data.data}
          onClose={() => { setCreating(false); setEditing(null); }}
          onSaved={() => { setCreating(false); setEditing(null); services.reload(); notify(c("saved")); }}
          onDeleted={() => { setEditing(null); services.reload(); notify(c("deleted")); }}
        />
      )}
      {(addingCat || editCat) && (
        <CategoryForm
          category={editCat}
          onClose={() => { setAddingCat(false); setEditCat(null); }}
          onSaved={() => { setAddingCat(false); setEditCat(null); reloadCats(); notify(c("saved")); }}
          onDeleted={() => { setEditCat(null); reloadCats(); notify(c("deleted")); }}
        />
      )}
      {staffFor && (
        <ServiceStaffModal service={staffFor} onClose={() => setStaffFor(null)}
          onSaved={() => { setStaffFor(null); services.reload(); notify(c("saved")); }} />
      )}
      {importing && (
        <MenuImportModal
          onClose={() => setImporting(false)}
          onImported={() => { services.reload(); categories.reload(); }}
        />
      )}
    </div>
  );
}

function ServiceForm({ service, categories, onClose, onSaved, onDeleted }: {
  service: Service | null; categories: Category[];
  onClose: () => void; onSaved: () => void; onDeleted: () => void;
}) {
  const t = useTranslations("app.services");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const [form, setForm] = useState({
    name: service?.name ?? "",
    duration_min: String(service?.duration_min ?? 30),
    price: service ? String(Number(service.price)) : "",
    service_category_id: service?.service_category_id ? String(service.service_category_id) : "",
  });
  const [busy, setBusy] = useState(false);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    const payload = {
      name: form.name,
      duration_min: Number(form.duration_min),
      price: Number(form.price),
      service_category_id: form.service_category_id ? Number(form.service_category_id) : null,
    };
    try {
      if (service) await patch(`/services/${service.id}`, payload);
      else await post("/services", payload);
      onSaved();
    } catch { notify(c("error"), "error"); setBusy(false); }
  }
  async function remove() {
    if (!service || !confirm(c("confirmDelete"))) return;
    try { await del(`/services/${service.id}`); onDeleted(); } catch { notify(c("error"), "error"); }
  }

  return (
    <Modal open onClose={onClose} title={service ? t("edit") : t("add")}>
      <form onSubmit={save} className="flex flex-col gap-4">
        <Field label={t("name")}>
          <Input required value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t("duration")}>
            <Input type="number" min={5} max={600} required dir="ltr" value={form.duration_min}
              onChange={(e) => setForm((f) => ({ ...f, duration_min: e.target.value }))} />
          </Field>
          <Field label={t("price")}>
            <Input type="number" min={0} step="0.01" required dir="ltr" value={form.price}
              onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))} />
          </Field>
        </div>
        <Field label={t("category")}>
          <Select value={form.service_category_id}
            onChange={(e) => setForm((f) => ({ ...f, service_category_id: e.target.value }))}>
            <option value="">{t("noCategory")}</option>
            {categories.map((cat) => <option key={cat.id} value={cat.id}>{cat.name}</option>)}
          </Select>
        </Field>
        <div className="mt-1 flex items-center justify-between gap-2">
          {service ? <Button type="button" variant="danger" onClick={remove}>{c("delete")}</Button> : <span />}
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
            <Button type="submit" disabled={busy}>{busy ? c("saving") : c("save")}</Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}

function CategoryForm({ category, onClose, onSaved, onDeleted }: {
  category: Category | null; onClose: () => void; onSaved: () => void; onDeleted: () => void;
}) {
  const t = useTranslations("app.services");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const [name, setName] = useState(category?.name ?? "");
  const [busy, setBusy] = useState(false);

  async function save(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      if (category) await patch(`/service-categories/${category.id}`, { name });
      else await post("/service-categories", { name });
      onSaved();
    } catch { notify(c("error"), "error"); setBusy(false); }
  }
  async function remove() {
    if (!category || !confirm(c("confirmDelete"))) return;
    try { await del(`/service-categories/${category.id}`); onDeleted(); } catch { notify(c("error"), "error"); }
  }

  return (
    <Modal open onClose={onClose} title={category ? t("editCategory") : t("addCategory")}>
      <form onSubmit={save} className="flex flex-col gap-4">
        <Field label={t("categoryName")}><Input required value={name} onChange={(e) => setName(e.target.value)} /></Field>
        <div className="flex items-center justify-between gap-2">
          {category ? <Button type="button" variant="danger" onClick={remove}>{c("delete")}</Button> : <span />}
          <div className="flex gap-2">
            <Button type="button" variant="ghost" onClick={onClose}>{c("cancel")}</Button>
            <Button type="submit" disabled={busy}>{busy ? c("saving") : c("save")}</Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}

function ServiceStaffModal({ service, onClose, onSaved }: {
  service: Service; onClose: () => void; onSaved: () => void;
}) {
  const t = useTranslations("app.services");
  const c = useTranslations("app.common");
  const nav = useTranslations("app.nav");
  const { notify } = useToast();
  const allStaff = useApi<{ data: StaffLite[] }>("/staff");
  const detail = useApi<{ data: { staff: StaffLite[] } }>(`/services/${service.id}`);
  const [selected, setSelected] = useState<Set<number> | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (detail.data) setSelected(new Set(detail.data.data.staff.map((s) => s.id)));
  }, [detail.data]);

  async function save() {
    if (!selected) return;
    setBusy(true);
    try { await put(`/services/${service.id}/staff`, { staff_ids: [...selected] }); onSaved(); }
    catch { notify(c("error"), "error"); setBusy(false); }
  }
  const toggle = (id: number) => setSelected((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; });

  const staffList = allStaff.data?.data ?? [];
  const failed = allStaff.error || detail.error;
  const ready = !allStaff.loading && !detail.loading && selected !== null;

  return (
    <Modal open onClose={onClose} title={`${t("assignStaff")} · ${service.name}`}>
      {failed ? (
        <LoadError onRetry={() => { allStaff.reload(); detail.reload(); }} />
      ) : !ready ? (
        <Spinner inline />
      ) : staffList.length === 0 ? (
        <div className="py-4 text-center">
          <p className="text-muted">{t("noStaffYet")}</p>
          <Link href="/staff" className="mt-3 inline-block rounded-xl bg-accent px-4 py-2.5 text-sm font-medium text-on-accent">
            {nav("staff")}
          </Link>
        </div>
      ) : (
        <div className="flex flex-col gap-1.5">
          <p className="mb-1 text-xs text-muted">{t("branchHint")}</p>
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
