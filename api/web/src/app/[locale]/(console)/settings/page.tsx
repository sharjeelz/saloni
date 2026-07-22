"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ApiError, patch, upload } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { useToast } from "@/components/ui/Toast";
import { Button, Card, Field, Input, LoadError, PageHeader, Select, Spinner } from "@/components/ui/kit";
import { BookingPagePanel } from "@/components/settings/BookingPagePanel";

type Salon = {
  name: string; slug: string; phone: string | null; vat_number: string | null;
  brand_color: string | null; timezone: string; locale: string; logo_path: string | null;
  notification_channel: string;
};

const TIMEZONES = ["Asia/Riyadh", "Asia/Dubai", "Asia/Kuwait", "Asia/Bahrain", "Asia/Qatar"];

export default function SettingsPage() {
  const t = useTranslations("app.settings");
  const c = useTranslations("app.common");
  const { notify } = useToast();
  const { data, loading, error, reload } = useApi<{ data: Salon }>("/salon");
  const [form, setForm] = useState<Salon | null>(null);
  const [busy, setBusy] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  useEffect(() => { if (data) setForm(data.data); }, [data]);

  async function onLogo(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append("logo", file);
      const res = await upload<{ logo_path: string }>("/salon/logo", fd);
      setForm((f) => (f ? { ...f, logo_path: res.logo_path } : f));
      notify(t("saved"));
    } catch (e2) {
      notify(e2 instanceof ApiError ? e2.message : c("error"), "error");
    } finally {
      setUploading(false);
      e.target.value = "";
    }
  }

  if (loading || !form) {
    return error ? <LoadError onRetry={reload} /> : <Spinner />;
  }

  const set = (k: keyof Salon) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setForm((f) => (f ? { ...f, [k]: e.target.value } : f));

  async function save(e: React.FormEvent) {
    e.preventDefault();
    if (!form) return;
    setErr(null);
    setBusy(true);
    try {
      await patch("/salon", {
        name: form.name,
        phone: form.phone || null,
        vat_number: form.vat_number || null,
        brand_color: form.brand_color || null,
        timezone: form.timezone,
        locale: form.locale,
        notification_channel: form.notification_channel,
      });
      notify(t("saved"));
    } catch (e2) {
      setErr(e2 instanceof ApiError ? e2.message : c("error"));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-6">
      <PageHeader title={t("title")} />
      <BookingPagePanel slug={form.slug} locale={form.locale} />
      <Card className="p-6">
        <form onSubmit={save} className="flex flex-col gap-4">
          <Field label={t("name")}><Input required minLength={2} value={form.name} onChange={set("name")} /></Field>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label={t("phone")}>
              <Input type="tel" dir="ltr" placeholder="+9665…" value={form.phone ?? ""} onChange={set("phone")} />
            </Field>
            <Field label={t("vatNumber")}>
              <Input dir="ltr" value={form.vat_number ?? ""} onChange={set("vat_number")} />
            </Field>
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label={t("timezone")}>
              <Select value={form.timezone} onChange={set("timezone")}>
                {TIMEZONES.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
              </Select>
            </Field>
            <Field label={t("locale")}>
              <Select value={form.locale} onChange={set("locale")}>
                <option value="ar">{t("arabic")}</option>
                <option value="en">{t("english")}</option>
              </Select>
            </Field>
          </div>
          <Field label={t("brandColor")}>
            <div className="flex items-center gap-3">
              <input
                type="color"
                value={form.brand_color ?? "#1E5C4A"}
                onChange={set("brand_color")}
                className="size-11 shrink-0 cursor-pointer rounded-lg border border-line bg-surface p-1"
              />
              <Input dir="ltr" value={form.brand_color ?? ""} placeholder="#1E5C4A" onChange={set("brand_color")} />
            </div>
          </Field>
          <p className="-mt-1 text-xs text-muted">{t("brandColorHint")}</p>

          {/* Logo upload */}
          <div>
            <span className="mb-1.5 block text-sm font-medium text-ink">{t("logoUpload")}</span>
            <div className="flex items-center gap-4">
              <span className="grid size-16 shrink-0 place-items-center overflow-hidden rounded-xl border border-line bg-surface-2">
                {form.logo_path ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={form.logo_path} alt="" className="size-full object-contain" />
                ) : (
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--color-muted)" strokeWidth="1.6"><rect x="3" y="3" width="18" height="18" rx="2" /><circle cx="9" cy="9" r="2" /><path d="M21 15l-5-5L5 21" /></svg>
                )}
              </span>
              <label className="cursor-pointer">
                <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" className="hidden" onChange={onLogo} disabled={uploading} />
                <span className="inline-flex items-center rounded-xl border border-line bg-surface px-4 py-2.5 text-sm font-medium text-ink hover:border-accent">
                  {uploading ? t("uploading") : t("chooseImage")}
                </span>
              </label>
            </div>
            <p className="mt-1.5 text-xs text-muted">{t("logoHint")}</p>
          </div>

          <Field label={t("notifChannel")}>
            <Select value={form.notification_channel} onChange={set("notification_channel")}>
              <option value="sms">{t("notifSms")}</option>
              <option value="whatsapp">{t("notifWhatsapp")}</option>
            </Select>
          </Field>
          <p className="-mt-1 text-xs text-muted">{t("notifChannelHint")}</p>

          {err && <p className="rounded-lg bg-crit/10 px-3 py-2 text-sm text-crit">{err}</p>}
          <div className="mt-1 flex justify-end">
            <Button type="submit" disabled={busy}>{busy ? c("saving") : c("save")}</Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
