import createMiddleware from "next-intl/middleware";
import { routing } from "./i18n/routing";

// Next.js 16 renamed the `middleware` convention to `proxy`. next-intl's
// request handler runs here to detect the locale and rewrite/redirect.
export default createMiddleware(routing);

export const config = {
  // Match everything except API routes, Next internals, and static files.
  matcher: "/((?!api|_next|_vercel|.*\\..*).*)",
};
