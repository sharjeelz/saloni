import { setRequestLocale } from "next-intl/server";
import BookingFlow from "@/components/booking/BookingFlow";

export default async function Page({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  setRequestLocale(locale);
  return <BookingFlow slug={slug} />;
}
