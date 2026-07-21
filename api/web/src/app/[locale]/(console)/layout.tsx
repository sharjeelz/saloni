import { AuthProvider } from "@/lib/auth";
import { ToastProvider } from "@/components/ui/Toast";
import Shell from "@/components/console/Shell";

export default function ConsoleLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthProvider>
      <ToastProvider>
        <Shell>{children}</Shell>
      </ToastProvider>
    </AuthProvider>
  );
}
