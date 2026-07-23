export type Offer = { id: number; image_path: string; caption: string | null; link_url: string | null };

/**
 * Vertical stack of the salon's promo banners. Sits in the left rail on desktop
 * and below the booking flow on mobile (so it never blocks the service list).
 * Images render in full (never cropped); a banner links out when a link is set.
 */
export function OffersRail({ offers }: { offers: Offer[] }) {
  if (offers.length === 0) return null;

  return (
    <div className="flex flex-col gap-3">
      {offers.map((o) => {
        const banner = (
          <div className="overflow-hidden rounded-2xl border border-line bg-surface shadow-[var(--shadow)]">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={o.image_path} alt={o.caption ?? ""} className="block w-full" />
            {o.caption && <p className="px-3.5 py-2.5 text-sm font-semibold text-ink">{o.caption}</p>}
          </div>
        );
        return o.link_url ? (
          <a key={o.id} href={o.link_url} target="_blank" rel="noopener noreferrer" className="block transition-opacity hover:opacity-95">
            {banner}
          </a>
        ) : (
          <div key={o.id}>{banner}</div>
        );
      })}
    </div>
  );
}
