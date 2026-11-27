<?php

namespace App\Domains\Document;

/**
 * Resolved document context for one parent record: allowed types and display sections.
 */
final readonly class DocumentContext
{
    /**
     * @param  list<string>|null  $allowedTypes  null when the parent has no catalogued types (company records)
     * @param  list<array{key: string, title: string, types: list<string>|null}>  $sections  `types` null marks the catch-all section
     */
    public function __construct(
        public string $title,
        public ?array $allowedTypes,
        public array $sections,
        public bool $retired,
    ) {}

    public function allows(?string $documentType): bool
    {
        if ($this->retired) {
            return false;
        }

        if ($this->allowedTypes === null) {
            return true;
        }

        return $documentType !== null && in_array($documentType, $this->allowedTypes, true);
    }

    public function sectionFor(?string $documentType): string
    {
        foreach ($this->sections as $section) {
            if ($section['types'] !== null && $documentType !== null && in_array($documentType, $section['types'], true)) {
                return $section['key'];
            }
        }

        foreach ($this->sections as $section) {
            if ($section['types'] === null) {
                return $section['key'];
            }
        }

        return $this->sections[0]['key'];
    }

    /**
     * @return list<array{key: string, title: string}>
     */
    public function presentSections(): array
    {
        return array_map(
            fn (array $section): array => ['key' => $section['key'], 'title' => $section['title']],
            $this->sections,
        );
    }

    /**
     * @return list<array{value: string, label: string, requires_expiry: bool, tracks_validity: bool}>
     */
    public function presentTypeOptions(): array
    {
        if ($this->retired || $this->allowedTypes === null) {
            return [];
        }

        return array_map(
            fn (string $type): array => [
                'value' => $type,
                'label' => DocumentTypeCatalog::label($type),
                'requires_expiry' => DocumentTypeCatalog::requiresExpiry($type),
                'tracks_validity' => DocumentTypeCatalog::tracksValidity($type),
            ],
            $this->allowedTypes,
        );
    }
}
