<?php

namespace App\Services\Onboarding;

use App\Support\ServiceCategoryPresets;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads photo(s) of a salon's price list and extracts a structured service list
 * (name, English name, duration, price, category) using Claude vision. Menus
 * may be Arabic, English, or bilingual — and the owner may upload more than one
 * photo (e.g. an Arabic sheet and an English sheet). Matching services across
 * languages are merged into one entry so nothing is duplicated. The result is a
 * *preview* the owner reviews and edits before anything is saved.
 */
class MenuScanner
{
    /**
     * @param  array<int, array{data:string, media_type:string}>  $images  base64 image(s)
     * @return array<int, array{name:string, name_en:?string, duration_min:int, price:float, category:?string, category_key:?string}>
     */
    public function scan(array $images): array
    {
        $config = config('services.anthropic');

        if (empty($config['key'])) {
            throw new RuntimeException('AI onboarding is not configured. Set ANTHROPIC_API_KEY.');
        }

        $content = [];
        foreach ($images as $img) {
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $img['media_type'], 'data' => $img['data']],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $this->prompt(count($images))];

        $response = Http::withHeaders([
            'x-api-key' => $config['key'],
            'anthropic-version' => $config['version'],
        ])->timeout(90)->post(rtrim($config['base_url'], '/') . '/v1/messages', [
            'model' => $config['menu_model'],
            'max_tokens' => 4096,
            'tools' => [$this->tool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'record_services'],
            'messages' => [['role' => 'user', 'content' => $content]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('The menu could not be read. Please try a clearer photo.');
        }

        $block = collect($response->json('content', []))->firstWhere('type', 'tool_use');
        $services = $block['input']['services'] ?? null;

        if (! is_array($services)) {
            throw new RuntimeException('No services were found on that image.');
        }

        return $this->normalize($services);
    }

    protected function prompt(int $imageCount): string
    {
        $multi = $imageCount > 1
            ? 'These photos may be the SAME menu in different languages (Arabic and English) or the front and '
                . 'back of one menu. '
            : '';

        $catList = collect(ServiceCategoryPresets::all())
            ->map(fn ($p) => "{$p['key']} ({$p['en']} / {$p['ar']})")
            ->implode(', ');

        return 'This is a salon price list / service menu. ' . $multi
            . 'Extract every DISTINCT service exactly once into the record_services tool. '
            . 'When the same service appears in both Arabic and English (match by price and position), MERGE it '
            . 'into a single entry: put the Arabic name in "name" and the English name in "name_en". '
            . 'If a service is written in only one language, put that name in "name" and leave "name_en" empty. '
            . 'Never list the same service twice. Keep names in their original script. '
            . 'For each service, set "category_key" to the SINGLE best-matching standard category from this list: '
            . $catList . '. Map variants and languages to the same key (e.g. "Nail", "Nails", "أظافر" → nails). '
            . 'Only if none of them reasonably fits, leave "category_key" empty and put the menu\'s own heading in '
            . '"category" instead. If a duration is not shown, estimate a sensible one. '
            . 'Prices are in Saudi Riyal — record the number only.';
    }

    /** Force clean, typed rows regardless of how the model formatted them. */
    protected function normalize(array $services): array
    {
        $rows = [];

        $validKeys = ServiceCategoryPresets::keys();

        foreach ($services as $s) {
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $en = trim((string) ($s['name_en'] ?? ''));
            $key = trim((string) ($s['category_key'] ?? ''));
            $key = in_array($key, $validKeys, true) ? $key : null;

            $rows[] = [
                'name' => mb_substr($name, 0, 255),
                'name_en' => $en !== '' && $en !== $name ? mb_substr($en, 0, 255) : null,
                'duration_min' => max(5, min(600, (int) round((float) ($s['duration_min'] ?? 30)))),
                'price' => max(0, round((float) ($s['price'] ?? 0), 2)),
                'category_key' => $key,
                'category' => ($cat = trim((string) ($s['category'] ?? ''))) !== '' ? mb_substr($cat, 0, 255) : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    protected function tool(): array
    {
        return [
            'name' => 'record_services',
            'description' => 'Record the salon services extracted from the menu image(s).',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'services' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Service name in its primary language (Arabic if present)'],
                                'name_en' => ['type' => 'string', 'description' => 'English name if the menu also shows one, else empty'],
                                'duration_min' => ['type' => 'integer', 'description' => 'Duration in minutes'],
                                'price' => ['type' => 'number', 'description' => 'Price in SAR, number only'],
                                'category_key' => [
                                    'type' => 'string',
                                    'enum' => [...ServiceCategoryPresets::keys(), ''],
                                    'description' => 'Standard category key, or empty if none fits',
                                ],
                                'category' => ['type' => 'string', 'description' => 'Menu\'s own heading (only when category_key is empty)'],
                            ],
                            'required' => ['name', 'price'],
                        ],
                    ],
                ],
                'required' => ['services'],
            ],
        ];
    }
}
