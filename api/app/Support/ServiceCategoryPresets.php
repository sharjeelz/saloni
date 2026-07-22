<?php

namespace App\Support;

/**
 * Canonical salon service categories (KSA women's-salon focused), each with an
 * Arabic and English name. Menus phrase categories many ways ("Nails", "Nail",
 * "أظافر") — the menu scanner maps each heading to one of these keys so they
 * collapse into a single bilingual category. Salons can still rename these or
 * add their own; the presets are a starting point, not a fixed taxonomy.
 */
class ServiceCategoryPresets
{
    /** @var array<int, array{key:string, ar:string, en:string}> */
    public const PRESETS = [
        ['key' => 'hair', 'ar' => 'الشعر', 'en' => 'Hair'],
        ['key' => 'hair_color', 'ar' => 'صبغ الشعر', 'en' => 'Hair Color'],
        ['key' => 'hair_treatment', 'ar' => 'علاج الشعر', 'en' => 'Hair Treatment'],
        ['key' => 'nails', 'ar' => 'الأظافر', 'en' => 'Nails'],
        ['key' => 'skin', 'ar' => 'العناية بالبشرة', 'en' => 'Skincare'],
        ['key' => 'makeup', 'ar' => 'المكياج', 'en' => 'Makeup'],
        ['key' => 'hair_removal', 'ar' => 'إزالة الشعر', 'en' => 'Hair Removal'],
        ['key' => 'lashes_brows', 'ar' => 'الرموش والحواجب', 'en' => 'Lashes & Brows'],
        ['key' => 'spa', 'ar' => 'سبا ومساج', 'en' => 'Spa & Massage'],
        ['key' => 'bridal', 'ar' => 'خدمات العرائس', 'en' => 'Bridal'],
        ['key' => 'henna', 'ar' => 'الحناء', 'en' => 'Henna'],
        ['key' => 'kids', 'ar' => 'الأطفال', 'en' => 'Kids'],
    ];

    /** @return array<int, array{key:string, ar:string, en:string}> */
    public static function all(): array
    {
        return self::PRESETS;
    }

    /** @return array{key:string, ar:string, en:string}|null */
    public static function find(string $key): ?array
    {
        foreach (self::PRESETS as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }

        return null;
    }

    /** @return array<int, string> the valid canonical keys */
    public static function keys(): array
    {
        return array_column(self::PRESETS, 'key');
    }
}
