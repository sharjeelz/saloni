"use client";

import { useCallback, useEffect, useState } from "react";
import { get } from "./api";

type State<T> = { data: T | null; loading: boolean; error: boolean };

/** GET a resource with loading/error state and a refetch. */
export function useApi<T>(path: string | null) {
  const [state, setState] = useState<State<T>>({ data: null, loading: true, error: false });

  const reload = useCallback(() => {
    if (path === null) {
      setState({ data: null, loading: false, error: false });
      return;
    }
    let live = true;
    setState((s) => ({ ...s, loading: true, error: false }));
    get<T>(path)
      .then((d) => live && setState({ data: d, loading: false, error: false }))
      .catch(() => live && setState({ data: null, loading: false, error: true }));
    return () => { live = false; };
  }, [path]);

  useEffect(() => { reload(); }, [reload]);

  return { ...state, reload };
}
