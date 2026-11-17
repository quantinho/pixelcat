<?php

namespace App\Http\Requests;

use App\Enums\Quality;
use App\Enums\Size;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The generate form, validated with Pixelforge's own rules so nothing we accept is rejected
 * there: prompt of 1–4,000 characters (Laravel counts multi-byte strings in characters),
 * a known size and quality, and at most 4 http(s) reference image URLs.
 *
 * The estimate endpoint uses the same rules, except that the prompt may be empty while the
 * user is typing and there is no id or pricing version yet.
 */
class GenerationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $submitting = ! $this->routeIs('generations.estimate');

        return [
            'prompt' => [$submitting ? 'required' : 'nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(Size::class)],
            'quality' => ['required', Rule::enum(Quality::class)],
            'reference_images' => ['nullable', 'list', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
            ...$submitting ? [
                'id' => ['required', 'uuid'],
                'pricing_version' => ['required', 'string'],
            ] : [],
        ];
    }

    public function prompt(): string
    {
        return (string) $this->validated('prompt');
    }

    public function size(): Size
    {
        return Size::from((string) $this->validated('size'));
    }

    public function quality(): Quality
    {
        return Quality::from((string) $this->validated('quality'));
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        /** @var list<string> $urls */
        $urls = $this->validated('reference_images') ?? [];

        return $urls;
    }
}
