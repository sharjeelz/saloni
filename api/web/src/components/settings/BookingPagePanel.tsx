"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import QRCode from "qrcode";
import { Button, Card } from "@/components/ui/kit";

/**
 * Share panel for a salon's public booking page: the link, a copy button,
 * and a scannable QR the owner can print or post. QR stays classic
 * black-on-white for reliable scanning regardless of brand colour.
 */
export function BookingPagePanel({ slug, locale }: { slug: string; locale: string }) {
  const t = useTranslations("app.settings");
  const [url, setUrl] = useState("");
  const [qr, setQr] = useState("");
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    const origin = window.location.origin;
    const link = `${origin}/${locale}/book/${slug}`;
    setUrl(link);
    QRCode.toDataURL(link, { width: 640, margin: 1, errorCorrectionLevel: "M" })
      .then(setQr)
      .catch(() => setQr(""));
  }, [slug, locale]);

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 1600);
    } catch {
      /* clipboard blocked — the link is visible to copy manually */
    }
  }

  function download() {
    if (!qr) return;
    const a = document.createElement("a");
    a.href = qr;
    a.download = `booking-qr-${slug}.png`;
    a.click();
  }

  return (
    <Card className="p-6">
      <h2 className="mb-1 text-lg font-semibold text-ink">{t("bookingPageTitle")}</h2>
      <p className="mb-5 text-sm text-muted">{t("bookingPageHint")}</p>

      <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
        <div className="grid shrink-0 place-items-center rounded-2xl border border-line bg-white p-3">
          {qr ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={qr} alt="" width={160} height={160} className="size-40" />
          ) : (
            <span className="size-40 animate-pulse rounded-lg bg-surface-2" />
          )}
        </div>

        <div className="flex min-w-0 flex-1 flex-col gap-3">
          <label className="flex flex-col gap-1.5">
            <span className="text-sm font-medium text-ink">{t("bookingLink")}</span>
            <div className="flex items-center gap-2">
              <input
                readOnly
                dir="ltr"
                value={url}
                onFocus={(e) => e.target.select()}
                className="min-w-0 flex-1 truncate rounded-xl border border-line bg-surface-2 px-3 py-2.5 text-sm text-ink"
              />
            </div>
          </label>
          <div className="flex flex-wrap gap-2">
            <Button type="button" variant="ghost" onClick={copy}>
              {copied ? t("copied") : t("copyLink")}
            </Button>
            <Button type="button" variant="ghost" onClick={download} disabled={!qr}>
              {t("downloadQr")}
            </Button>
            <a
              href={url || undefined}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center rounded-xl border border-line bg-surface px-4 py-2.5 text-sm font-medium text-ink hover:border-accent"
            >
              {t("openPage")}
            </a>
          </div>
        </div>
      </div>
    </Card>
  );
}
