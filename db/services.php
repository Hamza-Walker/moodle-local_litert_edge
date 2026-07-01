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
 * Web-service function definitions for the LiteRT Edge bridge.
 *
 * Both functions are AJAX-callable so the Chrome extension's content script
 * (running in the Moodle page's origin, authenticated by the teacher's session)
 * can call them via /lib/ajax/service.php. They are also grouped into a named
 * external service so they can optionally be reached with a token.
 *
 * @package    local_litert_edge
 * @copyright  2026 MOOTDACH Project
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_litert_edge_get_grading_data' => [
        'classname'    => 'local_litert_edge\external\get_grading_data',
        'methodname'   => 'execute',
        'description'  => 'Returns the rubric definition + submission text for an assignment and student.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/assign:grade',
    ],
    'local_litert_edge_save_ai_grade' => [
        'classname'    => 'local_litert_edge\external\save_ai_grade',
        'methodname'   => 'execute',
        'description'  => 'Validates and saves an AI-generated rubric grade produced in the browser.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/assign:grade',
    ],
    'local_litert_edge_get_grading_queue' => [
        'classname'    => 'local_litert_edge\external\get_grading_queue',
        'methodname'   => 'execute',
        'description'  => 'Lists students with a submission, for batch grading in the browser.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/assign:grade',
    ],
];

$services = [
    'LiteRT Edge grading bridge' => [
        'shortname'       => 'local_litert_edge',
        'functions'       => [
            'local_litert_edge_get_grading_data',
            'local_litert_edge_save_ai_grade',
            'local_litert_edge_get_grading_queue',
        ],
        'restrictedusers' => 0,
        'enabled'         => 1,
    ],
];
