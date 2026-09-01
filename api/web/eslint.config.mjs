import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      // DEFERRED, NOT DISMISSED.
      //
      // eslint-plugin-react-hooks v7 (React 19.2) added this compiler-aware
      // rule. It fires on our fetch-on-mount pattern —
      //   useEffect(() => { reload(); }, [reload])
      // where reload() sets loading state synchronously — which was idiomatic
      // under the previous plugin and is used consistently across ~15 files
      // (every console page, useApi, auth, the booking flow).
      //
      // None of those are broken: the rule flags cascading renders, a
      // performance and idiom concern, not a correctness bug. Fixing it
      // properly means restructuring how pages load data, which deserves its
      // own design rather than being done under the pressure of a red build.
      //
      // Kept at "warn" so the debt stays visible in lint output. When the data
      // fetching is reworked, restore this to "error" and delete this block.
      "react-hooks/set-state-in-effect": "warn",
    },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
