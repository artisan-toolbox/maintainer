<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Versionable\Readme;

use InvalidArgumentException;

final class Head
{
    /** @var array{src: string, alt: string, width: positive-int}|null */
    private ?array $logo = null;

    private ?string $title = null;

    /** @var list<string> */
    private array $descriptionLines = [];

    /** @var list<Badge> */
    private array $badges = [];

    public function withLogo(string $src, string $alt, int $width = 160): self
    {
        throw_if(trim($src) === '', InvalidArgumentException::class, 'The logo source cannot be blank.');

        throw_if($width < 1, InvalidArgumentException::class, 'The logo width must be greater than zero.');

        $this->logo = ['src' => $src, 'alt' => $alt, 'width' => $width];

        return $this;
    }

    public function withTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** @param string|array<array-key, string>|null $description */
    public function withDescription(string|array|null $description): self
    {
        $this->descriptionLines = array_values((array) $description);

        return $this;
    }

    public function appendDescriptionLine(string $line): self
    {
        $this->descriptionLines[] = $line;

        return $this;
    }

    public function addBadge(Badge $badge): self
    {
        $this->badges[] = $badge;

        return $this;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    /** @return array{src: string, alt: string, width: positive-int}|null */
    public function logo(): ?array
    {
        return $this->logo;
    }

    public function description(): ?string
    {
        return $this->descriptionLines === [] ? null : implode("\n\n", $this->descriptionLines);
    }

    /** @return list<string> */
    public function descriptionLines(): array
    {
        return $this->descriptionLines;
    }

    /** @return list<Badge> */
    public function badges(): array
    {
        return $this->badges;
    }
}
