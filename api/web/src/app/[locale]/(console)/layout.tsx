import { AuthProvider } from "@/lib/auth";
import Shell from "@/components/console/Shell";

export default function ConsoleLayout({ children }: { children: React.ReactNode }) {
  return (
    <AuthProvider>
      <Shell>{children}</Shell>
    </AuthProvider>
  );
}
