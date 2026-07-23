import type { ReactNode } from "react";

/**
 * The social platforms a salon can link. `base` is prepended when the owner
 * enters just a handle (e.g. "yoursalon") rather than a full URL. `bg` is the
 * platform's brand colour — the icon renders white on top of it.
 */
export const SOCIAL_PLATFORMS: { key: string; label: string; base: string; bg: string; icon: ReactNode }[] = [
  {
    key: "instagram",
    label: "Instagram",
    base: "https://instagram.com/",
    bg: "linear-gradient(45deg,#feda75 0%,#fa7e1e 25%,#d62976 50%,#962fbf 75%,#4f5bd5 100%)",
    icon: (
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="3" width="18" height="18" rx="5" />
        <circle cx="12" cy="12" r="4" />
        <circle cx="17.5" cy="6.5" r="1.1" fill="currentColor" stroke="none" />
      </svg>
    ),
  },
  {
    key: "facebook",
    label: "Facebook",
    base: "https://facebook.com/",
    bg: "#1877F2",
    icon: (
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
        <path d="M14 9h2.5V6H14c-2.2 0-3.5 1.3-3.5 3.5V11H8v3h2.5v7h3v-7H16l.5-3h-3v-1.3c0-.5.3-.7.8-.7Z" />
      </svg>
    ),
  },
  {
    key: "tiktok",
    label: "TikTok",
    base: "https://tiktok.com/@",
    bg: "#000000",
    icon: (
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
        <path d="M16 3c.3 2 1.6 3.4 3.5 3.6V9c-1.3 0-2.5-.4-3.5-1v6.2a5.2 5.2 0 1 1-5.2-5.2c.3 0 .5 0 .8.1V12a2.6 2.6 0 1 0 1.8 2.5V3H16Z" />
      </svg>
    ),
  },
  {
    key: "youtube",
    label: "YouTube",
    base: "https://youtube.com/@",
    bg: "#FF0000",
    icon: (
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
        <path d="M22 8.2a3 3 0 0 0-2.1-2.1C18 5.5 12 5.5 12 5.5s-6 0-7.9.6A3 3 0 0 0 2 8.2 31 31 0 0 0 1.6 12 31 31 0 0 0 2 15.8a3 3 0 0 0 2.1 2.1c1.9.6 7.9.6 7.9.6s6 0 7.9-.6a3 3 0 0 0 2.1-2.1c.3-1.3.4-2.6.4-3.8s-.1-2.5-.4-3.8ZM10 15V9l5.2 3L10 15Z" />
      </svg>
    ),
  },
];

/** Full URL for a stored value — accepts a full http(s) URL or a bare handle. */
export function socialUrl(base: string, value: string): string {
  const t = value.trim();
  if (/^https?:\/\//i.test(t)) return t;
  return base + t.replace(/^@/, "");
}

/** A brand-coloured circular button with a white glyph. */
export function SocialButton({ bg, label, size = "md", children }: { bg: string; label: string; size?: "sm" | "md"; children: ReactNode }) {
  return (
    <span
      aria-label={label}
      style={{ background: bg }}
      className={`grid shrink-0 place-items-center rounded-full text-white shadow-[var(--shadow)] ${size === "sm" ? "size-8" : "size-10"}`}
    >
      {children}
    </span>
  );
}

/** Icon row of the salon's set social links (nothing rendered if none set). */
export function SocialLinks({
  salon,
  className = "",
  size = "md",
}: {
  salon: Record<string, string | null | undefined>;
  className?: string;
  size?: "sm" | "md";
}) {
  const items = SOCIAL_PLATFORMS.filter((p) => (salon[p.key] ?? "").trim() !== "");
  if (items.length === 0) return null;

  return (
    <div className={`flex flex-wrap items-center gap-2 ${className}`}>
      {items.map((p) => (
        <a
          key={p.key}
          href={socialUrl(p.base, salon[p.key] as string)}
          target="_blank"
          rel="noopener noreferrer"
          aria-label={p.label}
          className="transition-transform hover:-translate-y-0.5"
        >
          <SocialButton bg={p.bg} label={p.label} size={size}>{p.icon}</SocialButton>
        </a>
      ))}
    </div>
  );
}
