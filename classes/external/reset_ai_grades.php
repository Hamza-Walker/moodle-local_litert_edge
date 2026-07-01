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
 * External function: reset_ai_grades.
 *
 * Undoes rubric grading for an assignment — deletes the rubric fillings and
 * grading instances, clears feedback comments, and resets each grade to
 * "not graded". Intended to reset a demo after AI grading. Since the current
 * design does not tag grades by origin, this clears ALL rubric grades for the
 * assignment (which, in a demo, are the AI-produced ones).
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

/**
 * Resets (undoes) rubric grades for an assignment.
 */
class reset_ai_grades extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
        ]);
    }

    /**
     * @param int $cmid
     * @return array
     */
    public static function execute($cmid): array {
        global $DB, $USER, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        // get_user_grade()/update_grade() reach into $PAGE; make sure it is set.
        if ($PAGE->context === null || $PAGE->context->id !== $context->id) {
            $PAGE->set_context($context);
            $PAGE->set_url(new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]));
            $PAGE->set_course($course);
            $PAGE->set_cm($cm);
        }

        $assign = new assign($context, $cm, $course);
        $instanceid = (int)$cm->instance;

        // Identify the rubric definition (to target its grading instances).
        $definitionid = 0;
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        $controller = $gradingmanager->get_active_controller();
        if ($controller && ($controller instanceof \gradingform_rubric_controller)) {
            $definition = $controller->get_definition();
            if ($definition) {
                $definitionid = (int)$definition->id;
            }
        }

        $grades = $DB->get_records('assign_grades', ['assignment' => $instanceid]);
        $reset = 0;

        foreach ($grades as $grade) {
            $changed = false;

            // 1. Remove rubric fillings + grading instances for this grade item.
            if ($definitionid) {
                $instances = $DB->get_records('grading_instances', [
                    'definitionid' => $definitionid,
                    'itemid' => $grade->id,
                ]);
                foreach ($instances as $instance) {
                    $DB->delete_records('gradingform_rubric_fillings', ['instanceid' => $instance->id]);
                    $DB->delete_records('grading_instances', ['id' => $instance->id]);
                    $changed = true;
                }
            }

            // 2. Remove the feedback comment, if any.
            if ($DB->get_manager()->table_exists('assignfeedback_comments')) {
                if ($DB->record_exists('assignfeedback_comments', ['assignment' => $instanceid, 'grade' => $grade->id])) {
                    $DB->delete_records('assignfeedback_comments', ['assignment' => $instanceid, 'grade' => $grade->id]);
                    $changed = true;
                }
            }

            // 3. Reset the grade to "not graded" (-1) and push to the gradebook.
            if ($grade->grade !== null && (float)$grade->grade >= 0) {
                $grade->grade = -1.0;
                $grade->grader = (int)$USER->id;
                try {
                    $assign->update_grade($grade);
                    $changed = true;
                } catch (\Exception $e) {
                    // Skip this one but keep going.
                    $changed = $changed || false;
                }
            }

            if ($changed) {
                $reset++;
            }
        }

        return [
            'success' => true,
            'message' => 'Reset ' . $reset . ' graded submission' . ($reset === 1 ? '' : 's') . '.',
            'count'   => $reset,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True if the reset ran'),
            'message' => new external_value(PARAM_RAW, 'Result message'),
            'count'   => new external_value(PARAM_INT, 'Number of submissions reset'),
        ]);
    }
}
