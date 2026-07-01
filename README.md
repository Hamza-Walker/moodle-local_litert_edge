# local_litert_edge — LiteRT Edge grading bridge

The Moodle half of **LiteRT Edge**. It lets the LiteRT Edge Chrome extension
grade assignment submissions with an on-device AI model, by exposing two
web-service endpoints:

- `local_litert_edge_get_grading_data` — returns the rubric definition + the
  student's submission text (read).
- `local_litert_edge_save_ai_grade` — validates the AI's rubric selections
  against the real rubric and writes the grade to the gradebook (write).

The plugin does **no** AI inference and stores no data of its own. It requires
no HTTPS or cross-origin isolation — all model work happens in the teacher's
browser extension. It is fully self-contained (no dependency on other plugins).

## Install

Site administration → Plugins → Install plugins → upload this ZIP → Upgrade.
(Or copy the `litert_edge` folder into `MOODLE_ROOT/local/` and visit the site
as admin to complete the upgrade.)

## How it's used

1. Install the companion **LiteRT Edge Chrome extension** and set up its model
   (its Setup page: pick model → Download & set up → Ready).
2. Create/settle an assignment whose **Grading method = Rubric**, with a
   student submission.
3. As a teacher, open the grading screen
   (`/mod/assign/view.php?id=<cmid>&action=grader&userid=<userid>`). A floating
   **"Grade with AI (on-device)"** button appears (added by the extension).
4. Click it: the extension reads the rubric + submission via
   `get_grading_data`, runs the model on the teacher's GPU, then posts the
   result to `save_ai_grade`, which validates and saves the grade.

## Security

- Both endpoints require the `mod/assign:grade` capability and validate the
  module context.
- The AI output is untrusted: `save_ai_grade` re-checks every
  `criterionid`/`levelid` against the assignment's real rubric and drops
  anything invented before saving.

## Requirements

- Moodle 4.2+.
- Assignment configured with a **rubric** advanced-grading method.
- Submission via **Online text** (a plain-text file submission is used as a
  fallback if there is no online text).

## Files

```
litert_edge/
├── version.php
├── settings.php                         Admin info page (no options to set)
├── db/services.php                      Web-service functions + named service
├── classes/external/get_grading_data.php
├── classes/external/save_ai_grade.php   Self-contained rubric-grade save
├── classes/privacy/provider.php         Null provider (stores nothing)
└── lang/en/local_litert_edge.php
```
