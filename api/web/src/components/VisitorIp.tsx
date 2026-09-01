import { headers } from "next/headers";

/**
 * Shows the visitor their own IP address.
 *
 * Its own async server component so the pages that render it can stay
 * synchronous — `headers()` must be awaited, and an async component cannot
 * use next-intl's `useTranslations` hook.
 *
 * Caddy sits in front of the Next.js container and sets X-Forwarded-For, so
 * the socket address Node sees is Caddy's, not the visitor's. The header may
 * hold a chain ("client, proxy1, proxy2"); the first entry is the client.
 */
export default async function VisitorIp() {
  const h = await headers();
  const forwarded = h.get("x-forwarded-for");
  const ip = forwarded?.split(",")[0]?.trim() || h.get("x-real-ip") || null;

  if (!ip) return null;

  return (
    <span className="font-mono text-xs text-muted" dir="ltr">
      {ip}
    </span>
  );
}
