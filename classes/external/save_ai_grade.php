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
 * External function: save_ai_grade.
 *
 * Receives the rubric grade the browser produced, re-validates every
 * criterion/level id against the assignment's real rubric (the model output is
 * untrusted), then writes it to the gradebook using Moodle's advanced-grading
 * tables. Self-contained: no dependency on other plugins.
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
use core_external\external_value;
use context_module;
use assign;
use stdClass;

/**
 * Validate and store an AI-generated rubric grade.
 */
class save_ai_grade extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'        => new external_value(PARAM_INT, 'Course module id of the assignment'),
            'userid'      => new external_value(PARAM_INT, 'The student user id'),
            'rubric_data' => new external_value(PARAM_RAW, 'JSON array of {criterionid, levelid, remark}'),
            'feedback'    => new external_value(PARAM_RAW, 'Optional overall feedback comment', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @param int $cmid
     * @param int $userid
     * @param string $rubricdata
     * @param string $feedback
     * @return array
     */
    public static function execute($cmid, $userid, $rubricdata, $feedback = ''): array {
        global $DB, $USER, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'userid' => $userid,
            'rubric_data' => $rubricdata,
            'feedback' => $feedback,
        ]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        // Decode the model output.
        $items = json_decode($params['rubric_data'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
            throw new \invalid_parameter_exception('rubric_data is not valid JSON.');
        }

        // Get the active rubric controller for this assignment.
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_active_controller();
        if (!$controller || !($controller instanceof \gradingform_rubric_controller)) {
            return ['success' => false, 'message' => 'This assignment is not set to rubric grading.'];
        }
        $definition = $controller->get_definition();
        if (!$definition || empty($definition->rubric_criteria)) {
            return ['success' => false, 'message' => 'No rubric definition found.'];
        }
        $definitionid = (int)$definition->id;

        // Build the set of valid criterion->level ids (model output is untrusted).
        $valid = [];
        foreach ($definition->rubric_criteria as $crit) {
            $cid = (int)$crit['id'];
            $valid[$cid] = [];
            if (!empty($crit['levels'])) {
                foreach ($crit['levels'] as $lvl) {
                    $valid[$cid][(int)$lvl['id']] = true;
                }
            }
        }

        // Keep only well-formed, in-rubric selections (one per criterion).
        $clean = [];
        foreach ($items as $item) {
            $item = (array)$item;
            if (!isset($item['criterionid'], $item['levelid'])) {
                continue;
            }
            $cid = (int)$item['criterionid'];
            $lid = (int)$item['levelid'];
            if (isset($valid[$cid][$lid]) && !isset($clean[$cid])) {
                $clean[$cid] = [
                    'levelid' => $lid,
                    'remark' => isset($item['remark']) ? clean_param($item['remark'], PARAM_TEXT) : '',
                ];
            }
        }
        if (empty($clean)) {
            return ['success' => false, 'message' => 'No valid rubric selections after validation.'];
        }

        // --- Save to the gradebook via the advanced-grading tables ---
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        // get_user_grade() and update_grade() reach into $PAGE; make sure it is set.
        if ($PAGE->context === null || $PAGE->context->id !== $context->id) {
            $PAGE->set_context($context);
            $PAGE->set_url(new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]));
            $PAGE->set_course($course);
            $PAGE->set_cm($cm);
        }

        $assign = new assign($context, $cm, $course);
        $grade = $assign->get_user_grade($params['userid'], true);

        // Create or update the grading instance for this grade item.
        $instancerecord = new stdClass();
        $instancerecord->definitionid = $definitionid;
        $instancerecord->raterid = (int)$USER->id;
        $instancerecord->itemid = $grade->id;
        $instancerecord->rawgrade = null;
        $instancerecord->status = 1; // ACTIVE.
        $instancerecord->feedback = '';
        $instancerecord->feedbackformat = FORMAT_HTML;
        $instancerecord->timemodified = time();

        $existing = $DB->get_record('grading_instances', [
            'definitionid' => $definitionid,
            'itemid' => $grade->id,
        ]);
        if ($existing) {
            $instancerecord->id = $existing->id;
            $DB->update_record('grading_instances', $instancerecord);
            $instanceid = (int)$existing->id;
            $DB->delete_records('gradingform_rubric_fillings', ['instanceid' => $instanceid]);
        } else {
            $instanceid = (int)$DB->insert_record('grading_instances', $instancerecord);
        }

        // Insert the rubric fillings and total the raw score.
        $totalscore = 0;
        foreach ($clean as $criterionid => $data) {
            $filling = new stdClass();
            $filling->instanceid = $instanceid;
            $filling->criterionid = (int)$criterionid;
            $filling->levelid = (int)$data['levelid'];
            $filling->remark = $data['remark'];
            $filling->remarkformat = FORMAT_HTML;
            $DB->insert_record('gradingform_rubric_fillings', $filling);

            $level = $DB->get_record('gradingform_rubric_levels', ['id' => $filling->levelid]);
            if ($level) {
                $totalscore += (float)$level->score;
            }
        }
        $DB->set_field('grading_instances', 'rawgrade', $totalscore, ['id' => $instanceid]);

        // Scale the rubric total to the assignment's max grade.
        $maxgrade = (float)$assign->get_instance()->grade;
        $rubricmax = self::rubric_max_score($definitionid);
        $finalgrade = ($rubricmax > 0 && $maxgrade > 0)
            ? ($totalscore / $rubricmax) * $maxgrade
            : $totalscore;

        $grade->grade = $finalgrade;
        $grade->grader = (int)$USER->id;
        try {
            $assign->update_grade($grade);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error saving grade: ' . $e->getMessage()];
        }

        // Optionally save an overall feedback comment.
        $savedfeedback = false;
        $feedbacktext = trim((string)$params['feedback']);
        if ($feedbacktext !== '') {
            $savedfeedback = self::save_feedback_comment($cm, $grade->id, $feedbacktext);
        }

        return [
            'success' => true,
            'message' => 'Grade saved: ' . round($finalgrade, 2) . ' / ' . round($maxgrade, 2)
                . ($savedfeedback ? ' (+ feedback)' : ''),
        ];
    }

    /**
     * Save an overall feedback comment into the assignment's Feedback comments.
     *
     * Writes directly to the assignfeedback_comments table (the standard feedback
     * subplugin). No-op if that subplugin's table is absent.
     *
     * @param \stdClass $cm    Course module record.
     * @param int $gradeid     assign_grades.id for this grade.
     * @param string $text     Feedback comment (plain text / HTML).
     * @return bool            True if saved.
     */
    private static function save_feedback_comment($cm, int $gradeid, string $text): bool {
        global $DB;
        if (!$DB->get_manager()->table_exists('assignfeedback_comments')) {
            return false;
        }
        $assignmentid = (int)$cm->instance;
        $existing = $DB->get_record('assignfeedback_comments', [
            'assignment' => $assignmentid,
            'grade' => $gradeid,
        ]);
        if ($existing) {
            $existing->commenttext = $text;
            $existing->commentformat = FORMAT_HTML;
            $DB->update_record('assignfeedback_comments', $existing);
        } else {
            $rec = new stdClass();
            $rec->assignment = $assignmentid;
            $rec->grade = $gradeid;
            $rec->commenttext = $text;
            $rec->commentformat = FORMAT_HTML;
            $DB->insert_record('assignfeedback_comments', $rec);
        }
        return true;
    }

    /**
     * Maximum possible rubric score for a definition.
     *
     * @param int $definitionid
     * @return float
     */
    private static function rubric_max_score(int $definitionid): float {
        global $DB;
        $criteria = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $definitionid]);
        $max = 0;
        foreach ($criteria as $criterion) {
            $maxlevel = $DB->get_field_sql(
                'SELECT MAX(score) FROM {gradingform_rubric_levels} WHERE criterionid = ?',
                [$criterion->id]
            );
            $max += (float)$maxlevel;
        }
        return $max;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if saved'),
            'message' => new external_value(PARAM_RAW, 'Result message'),
        ]);
    }
}
