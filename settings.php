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
 * Admin information page for the LiteRT Edge bridge.
 *
 * The bridge has no configurable options (all model settings live in the Chrome
 * extension); this page simply confirms the plugin is installed and explains
 * how it fits together.
 *
 * @package    local_litert_edge
 * @copyright  2026 MOOTDACH Project
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_litert_edge', get_string('pluginname', 'local_litert_edge'));

    $settings->add(new admin_setting_heading(
        'local_litert_edge/info',
        get_string('settings_info_heading', 'local_litert_edge'),
        get_string('settings_info_desc', 'local_litert_edge')
    ));

    $ADMIN->add('localplugins', $settings);
}
