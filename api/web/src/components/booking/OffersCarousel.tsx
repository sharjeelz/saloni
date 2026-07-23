export type Offer = { id: number; image_path: string; caption: string | null; link_url: string | null };

/**
 * Swipeable strip of the salon's promo banners. The banner image is shown in
 * full (never cropped) at its own aspect ratio; the caption sits below it. A
 * banner links out when the owner set a link. With more than one, a peek of the
 * next signals it scrolls.
 */
export function OffersCarousel({ offers }: { offers: Offer[] }) {
  if (offers.length === 0) return null;

  return (
    <div className="-mx-1 flex snap-x snap-mandatory items-start gap-3 overflow-x-auto px-1 pb-1">
      {offers.map((o) => {
        const banner = (
          <div className="overflow-hidden rounded-2xl border border-line bg-surface shadow-[var(--shadow)]">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={o.image_path} alt={o.caption ?? ""} className="block w-full" />
            {o.caption && <p className="px-3.5 py-2.5 text-sm font-semibold text-ink">{o.caption}</p>}
          </div>
        );
        return (
          <div key={o.id} className={`shrink-0 snap-start ${offers.length > 1 ? "w-[88%] sm:w-[75%]" : "w-full"}`}>
            {o.link_url ? (
              <a href={o.link_url} target="_blank" rel="noopener noreferrer" className="block transition-opacity hover:opacity-95">
                {banner}
              </a>
            ) : (
              banner
            )}
          </div>
        );
      })}
    </div>
  );
}
