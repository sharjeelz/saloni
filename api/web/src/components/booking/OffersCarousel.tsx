export type Offer = { id: number; image_path: string; caption: string | null; link_url: string | null };

/**
 * Swipeable strip of the salon's promo banners at the top of the booking page.
 * A banner links out when the owner set a link; a slight peek of the next
 * banner signals that it scrolls.
 */
export function OffersCarousel({ offers }: { offers: Offer[] }) {
  if (offers.length === 0) return null;

  return (
    <div className="mb-6 -mx-1 flex snap-x snap-mandatory gap-3 overflow-x-auto px-1 pb-1">
      {offers.map((o) => {
        const banner = (
          <div className="relative overflow-hidden rounded-2xl border border-line shadow-[var(--shadow)]">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={o.image_path} alt={o.caption ?? ""} className="h-36 w-full object-cover sm:h-44" />
            {o.caption && (
              <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/75 to-transparent p-3">
                <p className="text-sm font-semibold text-white">{o.caption}</p>
              </div>
            )}
          </div>
        );
        return (
          <div key={o.id} className={`shrink-0 snap-start ${offers.length > 1 ? "w-[86%] sm:w-[72%]" : "w-full"}`}>
            {o.link_url ? (
              <a href={o.link_url} target="_blank" rel="noopener noreferrer" className="block transition-transform hover:-translate-y-0.5">
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
