import { AuthProvider } from "@/lib/auth";
import { ToastProvider } from "@/components/ui/Toast";
import { ConfirmProvider } from "@/components/ui/confirm";
import Shell from "@/components/console/Shell";

export default function ConsoleLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthProvider>
      <ToastProvider>
        <ConfirmProvider>
          <Shell>{children}</Shell>
        </ConfirmProvider>
      </ToastProvider>
    </AuthProvider>
  );
}
