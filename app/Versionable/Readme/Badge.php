<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Versionable\Readme;

use LogicException;

final readonly class Badge
{
    private function __construct(
        private string $link,
        private ?string $imgSrc,
        private ?string $imgAlt,
        private ?string $title,
        private ?string $id,
    ) {}

    public static function make(
        string $link,
        string $imgSrc,
        ?string $imgAlt = null,
        ?string $title = null,
        ?string $id = null,
    ): self {
        return new self($link, $imgSrc, $imgAlt, $title, $id);
    }

    public static function releaseVersion(string $link): self
    {
        return new self($link, null, 'version', null, null);
    }

    public function link(): string
    {
        return $this->link;
    }

    public function imgSrc(?string $version = null): string
    {
        if ($this->imgSrc !== null) {
            return $this->imgSrc;
        }

        throw_if($version === null, LogicException::class, 'This badge needs a version. Declare public const string VERSION to render it outside a release.');

        $label = str_replace('-', '--', $version);

        return "https://img.shields.io/badge/version-{$label}-blue?style=flat-square";
    }

    public function imgAlt(): ?string
    {
        return $this->imgAlt;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function id(): ?string
    {
        return $this->id;
    }
}
