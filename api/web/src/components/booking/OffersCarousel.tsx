"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";

export type Offer = { id: number; image_path: string; caption: string | null; link_url: string | null };

/**
 * Vertical stack of the salon's promo banners (left rail on desktop, below the
 * flow on mobile). Each banner opens the lightbox — so every banner is
 * tappable regardless of whether a link is set. Images render in full.
 */
export function OffersRail({ offers, onOpen }: { offers: Offer[]; onOpen: (i: number) => void }) {
  const t = useTranslations("book");
  if (offers.length === 0) return null;

  return (
    <div className="flex flex-col gap-3">
      <p className="px-1 font-mono text-[11px] uppercase tracking-[0.18em] text-muted">{t("offersLabel")}</p>
      {offers.map((o, i) => (
        <button
          key={o.id}
          type="button"
          onClick={() => onOpen(i)}
          aria-label={o.caption ?? t("offersLabel")}
          className="group block overflow-hidden rounded-2xl border border-line bg-surface text-start shadow-[var(--shadow)] transition-all hover:-translate-y-0.5 hover:shadow-[var(--shadow-lg)]"
        >
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={o.image_path} alt="" className="block w-full transition-transform duration-500 group-hover:scale-[1.02]" />
          {o.caption && <p className="px-3.5 py-2.5 text-sm font-semibold text-ink">{o.caption}</p>}
        </button>
      ))}
    </div>
  );
}

/**
 * Full-screen lightbox carousel: the selected banner shown big in the centre,
 * swipe/arrow through the rest, with a CTA when the offer carries a link.
 */
export function OfferLightbox({ offers, startIndex, onClose }: { offers: Offer[]; startIndex: number; onClose: () => void }) {
  const t = useTranslations("book");
  const [i, setI] = useState(startIndex);
  const n = offers.length;
  const o = offers[i];

  const go = (d: number) => setI((p) => (p + d + n) % n);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
      if (e.key === "ArrowRight") go(1);
      if (e.key === "ArrowLeft") go(-1);
    };
    document.addEventListener("keydown", onKey);
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { document.removeEventListener("keydown", onKey); document.body.style.overflow = prev; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [n]);

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-black/85 backdrop-blur-sm" onClick={onClose}>
      <button
        onClick={onClose}
        aria-label={t("close")}
        className="absolute end-4 top-4 z-10 grid size-11 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20"
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M18 6 6 18M6 6l12 12" /></svg>
      </button>

      <div className="flex flex-1 items-center justify-center gap-2 p-4 sm:gap-4 sm:p-8" onClick={(e) => e.stopPropagation()}>
        {n > 1 && (
          <button onClick={() => go(-1)} aria-label={t("offerPrev")}
            className="grid size-11 shrink-0 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path className="rtl:hidden" d="M15 18l-6-6 6-6" /><path className="hidden rtl:block" d="M9 18l6-6-6-6" /></svg>
          </button>
        )}

        <figure className="flex max-h-full min-w-0 flex-1 flex-col items-center">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={o.image_path} alt={o.caption ?? ""} className="max-h-[72vh] w-auto max-w-full rounded-2xl object-contain shadow-2xl" />
          {(o.caption || o.link_url) && (
            <figcaption className="mt-4 flex w-full max-w-xl flex-col items-center gap-3 text-center">
              {o.caption && <p className="text-base font-semibold text-white">{o.caption}</p>}
              {o.link_url && (
                <a href={o.link_url} target="_blank" rel="noopener noreferrer"
                  className="inline-flex min-h-11 items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-semibold text-black transition-transform hover:-translate-y-0.5">
                  {t("offerOpen")}
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M7 17 17 7M8 7h9v9" /></svg>
                </a>
              )}
            </figcaption>
          )}
        </figure>

        {n > 1 && (
          <button onClick={() => go(1)} aria-label={t("offerNext")}
            className="grid size-11 shrink-0 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path className="rtl:hidden" d="M9 18l6-6-6-6" /><path className="hidden rtl:block" d="M15 18l-6-6 6-6" /></svg>
          </button>
        )}
      </div>

      {n > 1 && (
        <div className="flex items-center justify-center gap-2 pb-6" onClick={(e) => e.stopPropagation()}>
          {offers.map((_, d) => (
            <button key={d} onClick={() => setI(d)} aria-label={`${d + 1}`}
              className={`h-2 rounded-full transition-all ${d === i ? "w-6 bg-white" : "w-2 bg-white/40 hover:bg-white/70"}`} />
          ))}
        </div>
      )}
    </div>
  );
}
