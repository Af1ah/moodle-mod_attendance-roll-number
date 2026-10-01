<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_attendance\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_attendance\local\quick_attendance_service;

/**
 * Find students by ID number for Quick Attendance.
 *
 * @package    mod_attendance
 * @copyright  2026 Ariise LMS & ERP Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_student_by_idnumber extends external_api {
    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID.'),
            'sessionid' => new external_value(PARAM_INT, 'Attendance session ID.'),
            'grouptype' => new external_value(PARAM_INT, 'Session group ID.'),
            'groupid' => new external_value(PARAM_INT, 'Active group ID.'),
            'idnumber' => new external_value(PARAM_RAW_TRIMMED, 'Comma- or line-separated student ID numbers.'),
        ]);
    }

    /**
     * Execute the lookup.
     *
     * @param int $cmid Course module ID.
     * @param int $sessionid Attendance session ID.
     * @param int $grouptype Session group ID.
     * @param int $groupid Active group ID.
     * @param string $idnumber Comma- or line-separated student ID numbers.
     * @return array
     */
    public static function execute(
        int $cmid,
        int $sessionid,
        int $grouptype,
        int $groupid,
        string $idnumber
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), compact(
            'cmid',
            'sessionid',
            'grouptype',
            'groupid',
            'idnumber'
        ));

        $cm = get_coursemodule_from_id('attendance', $params['cmid'], 0, false, MUST_EXIST);
        self::validate_context(\context_module::instance($cm->id));
        [$att, $effectivegroupid] = quick_attendance_service::get_scope(
            $cm,
            $params['sessionid'],
            $params['grouptype'],
            $params['groupid']
        );

        return [
            'students' => quick_attendance_service::find_students(
                $att,
                $effectivegroupid,
                $params['idnumber']
            ),
        ];
    }

    /**
     * Define return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'students' => new external_multiple_structure(new external_single_structure([
                'query' => new external_value(PARAM_RAW, 'Requested student ID number.'),
                'found' => new external_value(PARAM_BOOL, 'Whether one student matched.'),
                'message' => new external_value(PARAM_TEXT, 'Lookup result message.'),
                'id' => new external_value(PARAM_INT, 'User ID.', VALUE_OPTIONAL),
                'idnumber' => new external_value(PARAM_RAW, 'Student ID number.', VALUE_OPTIONAL),
                'name' => new external_value(PARAM_TEXT, 'Student full name.', VALUE_OPTIONAL),
            ])),
        ]);
    }
}
