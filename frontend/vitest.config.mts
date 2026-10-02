import { fileURLToPath } from "node:url";
import { defineConfig } from "vitest/config";

// Component and state tests of the Family Portal (jsdom). Browser layout is
// covered by lint, type-check, build and the manual responsive checklist.
export default defineConfig({
  esbuild: { jsx: "automatic" },
  resolve: { alias: { "@": fileURLToPath(new URL("./", import.meta.url)) } },
  test: {
    environment: "jsdom",
    include: ["tests/**/*.test.{ts,tsx}"],
    setupFiles: ["./tests/setup.ts"],
    restoreMocks: true,
  },
});
