import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

const withNextIntl = createNextIntlPlugin("./src/i18n/request.ts");

const nextConfig: NextConfig = {
  // Emit a self-contained server bundle (.next/standalone) so the production
  // Docker image ships only the files it needs to run `node server.js`.
  output: "standalone",
};

export default withNextIntl(nextConfig);
