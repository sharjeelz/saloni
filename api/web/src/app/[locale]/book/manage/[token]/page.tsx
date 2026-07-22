import { setRequestLocale } from "next-intl/server";
import ManageBooking from "@/components/booking/ManageBooking";

export default async function Page({
  params,
}: {
  params: Promise<{ locale: string; token: string }>;
}) {
  const { locale, token } = await params;
  setRequestLocale(locale);
  return <ManageBooking token={token} />;
}
