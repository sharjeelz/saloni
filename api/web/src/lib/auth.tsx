"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { clearToken, get, post } from "./api";

export type User = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  role: "super_admin" | "owner" | "staff";
  salon_id: number | null;
};

export type Salon = {
  id: number;
  name: string;
  slug: string;
  plan: string;
  trial_ends_at: string | null;
  timezone: string;
  locale: string;
};

type Me = { user: User; salon: Salon | null };

type AuthState = {
  user: User | null;
  salon: Salon | null;
  loading: boolean;
  logout: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [salon, setSalon] = useState<Salon | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const me = await get<Me>("/auth/me");
      setUser(me.user);
      setSalon(me.salon);
    } catch {
      setUser(null);
      setSalon(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const logout = useCallback(async () => {
    try {
      await post("/auth/logout");
    } catch {
      /* ignore */
    }
    clearToken();
    setUser(null);
    setSalon(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, salon, loading, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
