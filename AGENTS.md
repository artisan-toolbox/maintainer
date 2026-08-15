# Maintainer Package Guidelines

These instructions supplement the workspace-level guidelines for work inside the Maintainer project.

## PHP Attributes

- Prefer native PHP attributes whenever the framework or library provides an equivalent to legacy metadata properties, annotations, or configuration.
- Console commands must use Laravel's `Signature`, `Description`, and other supported command attributes instead of their legacy property equivalents.
- Use a legacy form only when no compatible attribute exists or when an attribute would violate the supported compatibility matrix.
