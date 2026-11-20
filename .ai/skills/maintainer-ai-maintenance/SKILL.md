---
name: maintainer-ai-maintenance
description: Review Maintainer's default API models before a Maintainer release, or change its AI models, prompts, and structured release recommendations.
---

# Maintain Maintainer's AI workflows

- Before each Maintainer release, check the official [OpenAI model catalog](https://developers.openai.com/api/docs/models) and the model page for the ID in `app/Support/Ai/ConfiguredAiModel.php`. Review newer Sol versions, explicit API availability, structured output support, and the cost of switching. Change the default only after reviewing those differences.
- When changing the default, verify the actual Laravel AI request model for commit messages, release suggestions, notes, and changelog entries. Changelog diff summaries use the changelog model. Verify the structured output against the Responses API request and keep overrides for other providers usable.
- Check release recommendations with repository fixtures for internal maintenance, a new public capability, and incomplete evidence. Keep the local feedback and project locales independent. Maintainer's own published artifacts use the English project default.
- Keep the configuration reference, release guide, distributed Boost skill, and changelog in sync. Report whether validation used API fixtures or a live provider.
