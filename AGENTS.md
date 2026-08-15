# Maintainer Package Guidelines

These instructions supplement the workspace-level guidelines for work inside the Maintainer project.

## PHP Attributes

- Prefer native PHP attributes whenever the framework or library provides an equivalent to legacy metadata properties, annotations, or configuration.
- Console commands must use Laravel's `Signature`, `Description`, and other supported command attributes instead of their legacy property equivalents.
- Use a legacy form only when no compatible attribute exists or when an attribute would violate the supported compatibility matrix.

## Console Interfaces

- Treat every console interface as a first-class product surface. Flows must be elegant, polished, intentionally structured, and consistent with first-party Laravel tooling rather than merely functional.
- Use clear visual hierarchy, spacing, concise copy, consistent terminology, and actionable success and failure messages. The user should always understand the current state, the completed outcome, and any required next step.
- Build interactive console interfaces with Laravel Prompts whenever it provides the required interaction. Prefer its `text`, `textarea`, `password`, `confirm`, `select`, `multiselect`, `search`, and related prompt APIs over legacy command helpers, Symfony questions, or direct standard-input handling.
- Never leave the user without feedback during a perceptibly long operation. Use Laravel Prompts `spin` for work with indeterminate duration and `progress` for measurable or multi-step work such as analysis, builds, downloads, releases, and processing collections.
- Progress and spinner messages must describe the work currently being performed and conclude with a clear success or failure state. In non-interactive or continuous integration environments, provide equivalent plain-text progress without relying on animation.
- Use Laravel console components for non-interactive command output so Maintainer follows the presentation conventions of first-party Laravel commands.
- When presenting related labels and values, summaries, metadata, or other detail lists, use `$this->components->twoColumnDetail($label, $value)` whenever that format is applicable.
- Prefer the appropriate component for informational, warning, error, task, and table output instead of manually composing terminal formatting.
- Keep prompts deterministic and testable, provide sensible defaults when appropriate, and ensure commands remain usable in non-interactive environments where required.
