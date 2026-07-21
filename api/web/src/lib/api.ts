// Thin client for the Salooni API (token auth via Bearer header).

const BASE =
  process.env.NEXT_PUBLIC_API_URL?.replace(/\/$/, "") ?? "http://localhost:8000/api";

const TOKEN_KEY = "salooni_token";

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(token: string): void {
  window.localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken(): void {
  window.localStorage.removeItem(TOKEN_KEY);
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public body: unknown = null,
  ) {
    super(message);
  }
}

export async function api<T = unknown>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const token = getToken();
  const res = await fetch(`${BASE}${path}`, {
    ...options,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
  });

  if (!res.ok) {
    // Session expired mid-use — clear it and bounce to login (once).
    if (res.status === 401 && typeof window !== "undefined") {
      clearToken();
      const seg = window.location.pathname.split("/")[1];
      const locale = seg === "ar" || seg === "en" ? seg : "ar";
      if (!window.location.pathname.includes("/login")) {
        window.location.href = `/${locale}/login`;
      }
    }
    const body = await res.json().catch(() => ({}));
    throw new ApiError(res.status, (body as { message?: string })?.message ?? "Something went wrong.", body);
  }

  return res.status === 204 ? (null as T) : (res.json() as Promise<T>);
}

export const get = <T>(path: string) => api<T>(path);
export const post = <T>(path: string, data?: unknown) =>
  api<T>(path, { method: "POST", body: data ? JSON.stringify(data) : undefined });
export const patch = <T>(path: string, data?: unknown) =>
  api<T>(path, { method: "PATCH", body: data ? JSON.stringify(data) : undefined });
export const put = <T>(path: string, data?: unknown) =>
  api<T>(path, { method: "PUT", body: data ? JSON.stringify(data) : undefined });
export const del = <T>(path: string) => api<T>(path, { method: "DELETE" });
