# moodle-local_litert_edge

The **Moodle side** of LiteRT Edge — on-device AI grading for Moodle assignments.

This plugin performs **no AI**. It exposes three web-service endpoints that a
companion Chrome extension calls to read the rubric + submission and to save the
AI-produced grade. All model inference runs in the teacher's browser on their own
GPU. The plugin requires no HTTPS or special configuration.

👉 Client half (the browser extension that runs the model):
[litert-edge-extension](https://github.com/Hamza-Walker/litert-edge-extension).

---

## What it provides

| Web-service function | Type | Purpose |
|---|---|---|
| `local_litert_edge_get_grading_data` | read | Returns the rubric (criteria + levels), the submission text (online text, with a plain-text file fallback), the assignment name, and any grading instructions. |
| `local_litert_edge_save_ai_grade` | write | Re-validates the AI's rubric selections against the real rubric, writes the grade to the gradebook, and saves an optional feedback comment. |
| `local_litert_edge_get_grading_queue` | read | Lists submitted students (and whether already graded) for batch grading. |

All three are AJAX-callable (the extension's content script calls them
same-origin with the teacher's session) and require the `mod/assign:grade`
capability.

## Security

The AI output is treated as **untrusted**: `save_ai_grade` re-checks every
`criterionid`/`levelid` against the assignment's real rubric and drops anything
invented before saving. Every endpoint validates the module context and the
`mod/assign:grade` capability. The plugin stores no personal data of its own
(null privacy provider).

## Install

- **Manual:** copy this repo's contents into `MOODLE_ROOT/local/litert_edge/`,
  then visit the site as admin to complete the upgrade.
- **This project (Docker auto-pull):** the repo is bind-mounted into the
  container at `local/litert_edge`. To update:
  ```bash
  cd ~/docker/moodle-local_litert_edge && git pull
  ```
  If you change PHP, bump `$plugin->version` in `version.php` so Moodle runs its
  upgrade at the next admin page load.

## Requirements

- Moodle **4.2+** (uses the `core_external` namespace).
- Assignment with **Grading method = Rubric**.
- The companion Chrome/Edge extension installed in the teacher's browser.

## Files

```
version.php                         Plugin identity & version
settings.php                        Admin info page (no configurable options)
db/services.php                     Web-service function + external service definitions
classes/external/get_grading_data.php
classes/external/get_grading_queue.php
classes/external/save_ai_grade.php
classes/privacy/provider.php        Null privacy provider (stores nothing)
lang/en/local_litert_edge.php       Language strings
```

## License

GPL v3 or later.
