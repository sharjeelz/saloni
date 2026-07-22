<?php

namespace App\Services\Onboarding;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads a photo/scan of a salon's price list and extracts a structured list of
 * services (name, duration, price, category) using Claude vision. Menus may be
 * Arabic, English, or mixed. The result is a *preview* the owner reviews and
 * edits before anything is saved — extraction is never trusted blindly.
 */
class MenuScanner
{
    /**
     * @return array<int, array{name:string, duration_min:int, price:float, category:?string}>
     */
    public function scan(string $base64Image, string $mediaType): array
    {
        $config = config('services.anthropic');

        if (empty($config['key'])) {
            throw new RuntimeException('AI onboarding is not configured. Set ANTHROPIC_API_KEY.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $config['key'],
            'anthropic-version' => $config['version'],
        ])->timeout(60)->post(rtrim($config['base_url'], '/') . '/v1/messages', [
            'model' => $config['menu_model'],
            'max_tokens' => 4096,
            'tools' => [$this->tool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'record_services'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64Image],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'This is a salon price list / service menu. Extract every service into the '
                            . 'record_services tool. Keep each service name in its original language (Arabic or '
                            . 'English). Group services under the category headings shown on the menu; if there '
                            . 'are none, leave the category empty. If a duration is not shown, estimate a sensible '
                            . 'one for that kind of service. Prices are in Saudi Riyal — record the number only.',
                    ],
                ],
            ]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('The menu could not be read. Please try a clearer photo.');
        }

        // Pull the tool_use block the model was forced to produce.
        $block = collect($response->json('content', []))
            ->firstWhere('type', 'tool_use');

        $services = $block['input']['services'] ?? null;

        if (! is_array($services)) {
            throw new RuntimeException('No services were found on that image.');
        }

        return $this->normalize($services);
    }

    /** Force clean, typed rows regardless of how the model formatted them. */
    protected function normalize(array $services): array
    {
        $rows = [];

        foreach ($services as $s) {
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'name' => mb_substr($name, 0, 255),
                'duration_min' => max(5, min(600, (int) round((float) ($s['duration_min'] ?? 30)))),
                'price' => max(0, round((float) ($s['price'] ?? 0), 2)),
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
            'description' => 'Record the salon services extracted from the menu image.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'services' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Service name, original language'],
                                'duration_min' => ['type' => 'integer', 'description' => 'Duration in minutes'],
                                'price' => ['type' => 'number', 'description' => 'Price in SAR, number only'],
                                'category' => ['type' => 'string', 'description' => 'Menu category heading, or empty'],
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
