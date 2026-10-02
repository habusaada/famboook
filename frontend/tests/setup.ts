import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Some Node versions shadow jsdom's Web Storage with an unusable global. The
// tests only need to prove that NOTHING is written to it, so a plain
// in-memory Storage stands in when the real one is missing.
class MemoryStorage implements Storage {
  private items = new Map<string, string>();

  get length(): number {
    return this.items.size;
  }

  clear(): void {
    this.items.clear();
  }

  getItem(key: string): string | null {
    return this.items.get(key) ?? null;
  }

  key(index: number): string | null {
    return Array.from(this.items.keys())[index] ?? null;
  }

  removeItem(key: string): void {
    this.items.delete(key);
  }

  setItem(key: string, value: string): void {
    this.items.set(key, String(value));
  }
}

for (const name of ["localStorage", "sessionStorage"] as const) {
  if (typeof window[name]?.setItem !== "function") {
    Object.defineProperty(window, name, { configurable: true, value: new MemoryStorage() });
  }
}

afterEach(() => {
  cleanup();
});
