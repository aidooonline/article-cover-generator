# Article Cover Generator

One-click AI cover and in-article images for WordPress posts, powered by [fal.ai](https://fal.ai). Generate a brand-consistent cover image (and optional extra in-article images) from the post editor or the posts list, compress it for the web, and set it as the featured image. Reusable across sites.

## How it works

- **Path 1 (default, recommended):** fal generates the photographic *scene*; the plugin composites the fixed editorial layout (panels, frame lines, champagne strip) and the title in the brand font, in code. Result: pixel-identical layout and crisp, correct text on every cover, any title length.
- **Path 2:** a single all-in-one prompt is sent to a typography-capable model. Simpler, but layout and long-title text are less reliable.

The scene is described by editable **prompt templates** with placeholder variables (`{title}`, `{property_scene}`, `{location}`, `{section_subject}`, ...), filled automatically, by an optional LLM refine step, or manually.

## Install

1. Copy this folder into `wp-content/plugins/` (or deploy via Git), then activate it in Plugins.
2. Settings → Cover Generator → **Settings**: paste your fal API key, set the model (default `fal-ai/flux/dev`) and options.
3. Settings → Cover Generator → **Diagnostics**: confirm the server has an image library with WebP support (this is what makes compression reliable).
4. Settings → Cover Generator → **Prompts**: review or refine the cover and extra-image templates.

## fal API

- Auth header: `Authorization: Key <FAL_KEY>`
- Generate: `POST https://queue.fal.run/{model-id}` (queued) or `https://fal.run/{model-id}` (sync)
- List image models: `GET https://api.fal.ai/v1/models?category=text-to-image&status=active`

## Build status (sprints)

- **P0:** scaffold, settings, diagnostics, prompt library. Done.
- **P1:** fal client + live model dropdown + key test. Done.
- **P2 (this commit):** generate + download from the editor button (template -> prompt -> fal -> temp file). Done.
- **P3:** compress (Imagick/GD, resize, WebP/JPEG) + `media_handle_sideload` + `set_post_thumbnail`; Path 1 code overlay.
- **P4:** regenerate, posts-list row action, the extra in-article images.
- **P5:** error/rate handling, orphan cleanup, cache purge, docs.

## Prompt aesthetic (0.3.1)

The default cover and extra-image scene prompts direct the model toward an
affluent, professional, aspirational look: well-dressed, confident, successful
people in clean, modern, upscale surroundings, with a warm, rich, premium palette.
They explicitly steer away from poor, run-down, dated or low-income looking
imagery. Existing installs keep their saved prompts; reset in Settings or edit the
Cover/Extra prompt fields to adopt the new defaults.

## License

GPL-2.0-or-later.
