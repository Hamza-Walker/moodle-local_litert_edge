<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * External function: get_grading_data.
 *
 * Returns everything the browser extension needs to grade one student's
 * submission: the rubric definition, the submission text, and any grading
 * instructions. Read-only.
 *
 * @package    local_litert_edge
 * @copyright  2026 MOOTDACH Project
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_litert_edge\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/grade/grading/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use context_module;
use assign;

/**
 * Returns the rubric definition and submission text the browser needs to grade.
 */
class get_grading_data extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course module id of the assignment'),
            'userid' => new external_value(PARAM_INT, 'The student user id to grade'),
        ]);
    }

    /**
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public static function execute($cmid, $userid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'userid' => $userid,
        ]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $assign = new assign($context, $cm, $course);
        $instance = $assign->get_instance();

        // --- Rubric definition (Moodle advanced grading) ---
        $rubric = [];
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_active_controller();
        if ($controller && ($controller instanceof \gradingform_rubric_controller)) {
            $definition = $controller->get_definition();
            if ($definition && !empty($definition->rubric_criteria)) {
                foreach ($definition->rubric_criteria as $crit) {
                    $levels = [];
                    if (!empty($crit['levels'])) {
                        foreach ($crit['levels'] as $lvl) {
                            $levels[] = [
                                'levelid'    => (int)$lvl['id'],
                                'definition' => (string)$lvl['definition'],
                                'score'      => (float)$lvl['score'],
                            ];
                        }
                    }
                    $rubric[] = [
                        'criterionid' => (int)$crit['id'],
                        'description' => (string)$crit['description'],
                        'levels'      => $levels,
                    ];
                }
            }
        }

        // --- Submission text (online text, with a plain-text file fallback) ---
        $submissiontext = '';
        $submission = $assign->get_user_submission($params['userid'], false);
        if ($submission) {
            $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
            if ($onlinetext && isset($onlinetext->onlinetext) && trim($onlinetext->onlinetext) !== '') {
                $submissiontext = trim(html_to_text($onlinetext->onlinetext, 0, false));
            } else {
                // Fallback: read any plain-text/markdown file the student submitted.
                $submissiontext = self::read_text_files($context, $submission->id);
            }
        }

        // --- Per-assignment AI options from local_smartgradeai (if installed) ---
        $systemmessage = '';
        $complexity = 'general';
        if ($DB->get_manager()->table_exists('local_smartgradeai_opts')) {
            $opts = $DB->get_record('local_smartgradeai_opts', ['assignmentid' => $cm->instance]);
            if ($opts) {
                $systemmessage = (string)$opts->system_message;
                $complexity = (string)$opts->complexity;
            }
        }

        return [
            'assignment_name' => format_string($instance->name),
            'rubric'          => $rubric,
            'submission_text' => $submissiontext,
            'system_message'  => $systemmessage,
            'complexity'      => $complexity,
            'max_grade'       => (float)$instance->grade,
            'has_submission'  => $submission ? true : false,
        ];
    }

    /**
     * Read plain-text content from any text files a student submitted.
     *
     * @param \context_module $context
     * @param int $submissionid
     * @return string
     */
    private static function read_text_files(\context_module $context, int $submissionid): string {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submissionid,
            'filename',
            false
        );
        $texts = [];
        foreach ($files as $file) {
            $name = strtolower($file->get_filename());
            if (preg_match('/\.(txt|md|csv|json|py|java|c|cpp|js|html?)$/', $name)) {
                // Cap per-file size to keep the prompt manageable.
                $content = $file->get_content();
                if (strlen($content) > 20000) {
                    $content = substr($content, 0, 20000) . "\n…(truncated)…";
                }
                $texts[] = $content;
            }
        }
        return trim(implode("\n\n", $texts));
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assignment_name' => new external_value(PARAM_RAW, 'Assignment name'),
            'rubric' => new external_multiple_structure(
                new external_single_structure([
                    'criterionid' => new external_value(PARAM_INT, 'Criterion id'),
                    'description'  => new external_value(PARAM_RAW, 'Criterion description'),
                    'levels' => new external_multiple_structure(
                        new external_single_structure([
                            'levelid'    => new external_value(PARAM_INT, 'Level id'),
                            'definition' => new external_value(PARAM_RAW, 'Level definition'),
                            'score'      => new external_value(PARAM_FLOAT, 'Level score'),
                        ])
                    ),
                ])
            ),
            'submission_text' => new external_value(PARAM_RAW, 'Plain-text student submission'),
            'system_message'  => new external_value(PARAM_RAW, 'Per-assignment grading instructions'),
            'complexity'      => new external_value(PARAM_RAW, 'Subject/domain hint'),
            'max_grade'       => new external_value(PARAM_FLOAT, 'Assignment max grade'),
            'has_submission'  => new external_value(PARAM_BOOL, 'Whether a submission exists'),
        ]);
    }
}
