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
 * External function: get_grading_queue.
 *
 * Returns the list of students who have a submission for an assignment, so the
 * browser extension can grade them one after another (batch grading).
 *
 * @package    local_litert_edge
 * @copyright  2026 MOOTDACH Project
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_litert_edge\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use context_module;
use assign;
use core_user;

/**
 * Lists students with a submission, and whether each is already graded.
 */
class get_grading_queue extends external_api {

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
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $assign = new assign($context, $cm, $course);
        $instanceid = (int)$cm->instance;

        // Latest submitted individual submissions for this assignment.
        $submissions = $DB->get_records('assign_submission', [
            'assignment' => $instanceid,
            'latest' => 1,
            'status' => 'submitted',
        ]);

        $students = [];
        foreach ($submissions as $sub) {
            $userid = (int)$sub->userid;
            if ($userid <= 0) {
                continue; // Group submission row — skip (individual grading only).
            }
            $user = core_user::get_user($userid, '*', IGNORE_MISSING);
            if (!$user) {
                continue;
            }

            // Already graded?
            $graded = false;
            $grade = $DB->get_record('assign_grades', [
                'assignment' => $instanceid,
                'userid' => $userid,
            ], 'id, grade', IGNORE_MULTIPLE);
            if ($grade && $grade->grade !== null && (float)$grade->grade >= 0) {
                $graded = true;
            }

            $students[] = [
                'userid'   => $userid,
                'fullname' => fullname($user),
                'graded'   => $graded,
            ];
        }

        // Stable order by name for a predictable run.
        usort($students, function ($a, $b) {
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        return [
            'assignment_name' => format_string($assign->get_instance()->name),
            'students'        => $students,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assignment_name' => new external_value(PARAM_RAW, 'Assignment name'),
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'userid'   => new external_value(PARAM_INT, 'Student user id'),
                    'fullname' => new external_value(PARAM_RAW, 'Student full name'),
                    'graded'   => new external_value(PARAM_BOOL, 'Whether already graded'),
                ])
            ),
        ]);
    }
}
