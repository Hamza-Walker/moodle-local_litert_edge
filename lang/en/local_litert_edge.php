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
 * Language strings for local_litert_edge (bridge).
 *
 * @package    local_litert_edge
 * @copyright  2026 MOOTDACH Project
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'LiteRT Edge (browser grading bridge)';

// Admin info page.
$string['settings_info_heading'] = 'About this plugin';
$string['settings_info_desc'] = 'This is the Moodle side of LiteRT Edge. It exposes two web-service functions that the LiteRT Edge Chrome extension calls to read a rubric + submission and to save the AI-generated grade. All AI inference runs in the teacher\'s browser on their own GPU — Moodle does no inference and needs no configuration here. On an assignment set to <em>rubric</em> grading, teachers will see a "Grade with AI (on-device)" button on the grading screen once the extension is installed.';

// Privacy.
$string['privacy:metadata'] = 'The LiteRT Edge bridge does not store personal data of its own. It reads rubric and submission data to return to the teacher\'s browser, and saves grades through Moodle\'s standard grading APIs.';
