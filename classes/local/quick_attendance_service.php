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

namespace mod_attendance\local;

use context_module;
use mod_attendance_structure;
use mod_attendance_take_page_params;

/**
 * Server-side operations for the Quick Attendance interface.
 *
 * @package    mod_attendance
 * @copyright  2026 Ariise LMS & ERP Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quick_attendance_service {
    /**
     * Build and validate the attendance scope for a Quick Attendance request.
     *
     * @param \stdClass $cm Course module record.
     * @param int $sessionid Session ID.
     * @param int $grouptype Session group ID, or zero for a common session.
     * @param int $groupid Active group for a common session.
     * @return array{0: mod_attendance_structure, 1: int}
     */
    public static function get_scope(\stdClass $cm, int $sessionid, int $grouptype, int $groupid): array {
        global $CFG, $DB;

        // Ajax entry points do not load the activity page bootstrap, which normally includes these constants.
        require_once($CFG->dirroot . '/mod/attendance/locallib.php');

        $course = get_course($cm->course);
        require_login($course, true, $cm);

        $context = context_module::instance($cm->id);
        require_capability('mod/attendance:takeattendances', $context);

        $attrecord = $DB->get_record('attendance', ['id' => $cm->instance], '*', MUST_EXIST);
        $session = $DB->get_record(
            'attendance_sessions',
            ['id' => $sessionid, 'attendanceid' => $attrecord->id],
            '*',
            MUST_EXIST
        );
        if ((int)$session->groupid !== $grouptype) {
            throw new \invalid_parameter_exception(get_string('invalidsessiongroup', 'attendance'));
        }

        $effectivegroupid = $grouptype === mod_attendance_structure::SESSION_COMMON ? $groupid : $grouptype;
        self::require_group_access($cm, $context, $effectivegroupid);

        $pageparams = new mod_attendance_take_page_params();
        $pageparams->sessionid = $sessionid;
        $pageparams->grouptype = $grouptype;
        $pageparams->group = $groupid;
        $pageparams->init();

        $att = new mod_attendance_structure($attrecord, $cm, $course, $context, $pageparams);

        return [$att, $effectivegroupid];
    }

    /**
     * Find listable students by one or more configured ID numbers.
     *
     * @param mod_attendance_structure $att Attendance structure.
     * @param int $groupid Effective group ID.
     * @param string $idnumbers Comma- or line-separated student ID numbers.
     * @return array
     */
    public static function find_students(
        mod_attendance_structure $att,
        int $groupid,
        string $idnumbers
    ): array {
        global $DB;

        require_capability('moodle/site:viewuseridentity', $att->context);
        $requestedids = preg_split('/[\r\n,]+/', $idnumbers);
        $requestedids = array_values(array_filter(
            array_map('trim', $requestedids),
            static fn($id): bool => $id !== ''
        ));
        if (!$requestedids) {
            throw new \invalid_parameter_exception(get_string('pleaseenteridnumber', 'attendance'));
        }

        $uniqueids = [];
        foreach ($requestedids as $requestedid) {
            $lookupkey = self::get_idnumber_lookup_key($requestedid);
            if (!isset($uniqueids[$lookupkey])) {
                $uniqueids[$lookupkey] = $requestedid;
            }
        }

        $groupids = self::get_group_ids($att, $groupid);
        [$enrolledsql, $params] = get_enrolled_sql(
            $att->context,
            'mod/attendance:canbelisted',
            $groupids
        );
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false);
        $sql = "SELECT u.id, u.idnumber, {$namefields->selects}
                  FROM {user} u
                  JOIN ($enrolledsql) eu ON eu.id = u.id
                 WHERE u.idnumber IS NOT NULL AND u.idnumber <> ''
              ORDER BY u.id";
        $studentsbyidnumber = [];
        foreach ($DB->get_records_sql($sql, $params) as $student) {
            $lookupkey = self::get_idnumber_lookup_key(trim($student->idnumber));
            $studentsbyidnumber[$lookupkey][] = $student;
        }

        $results = [];
        foreach ($uniqueids as $lookupkey => $requestedid) {
            $matches = $studentsbyidnumber[$lookupkey] ?? [];
            if (!$matches) {
                $results[] = [
                    'query' => $requestedid,
                    'found' => false,
                    'message' => get_string('studentnotfound', 'attendance', $requestedid),
                ];
                continue;
            }
            if (count($matches) > 1) {
                $results[] = [
                    'query' => $requestedid,
                    'found' => false,
                    'message' => get_string('studentidnumberambiguous', 'attendance', $requestedid),
                ];
                continue;
            }

            $student = reset($matches);
            $results[] = [
                'query' => $requestedid,
                'found' => true,
                'message' => '',
                'id' => (int)$student->id,
                'idnumber' => $student->idnumber,
                'name' => fullname($student),
            ];
        }

        return $results;
    }

    /**
     * Build an ID-number lookup key, ignoring leading zeroes for numeric IDs.
     *
     * @param string $idnumber Student ID number.
     * @return string
     */
    private static function get_idnumber_lookup_key(string $idnumber): string {
        if (!ctype_digit($idnumber)) {
            return 'text:' . $idnumber;
        }

        $normalisedid = ltrim($idnumber, '0');
        return 'number:' . ($normalisedid === '' ? '0' : $normalisedid);
    }

    /**
     * Get the group restriction accepted by enrolment APIs.
     *
     * @param mod_attendance_structure $att Attendance structure.
     * @param int $groupid Effective group ID.
     * @return int|int[]
     */
    private static function get_group_ids(mod_attendance_structure $att, int $groupid) {
        if (!empty($att->cm->groupingid) && $groupid === 0) {
            return array_keys(groups_get_all_groups(
                $att->cm->course,
                0,
                $att->cm->groupingid,
                'g.id'
            ));
        }
        return $groupid;
    }

    /**
     * Require access to the effective group.
     *
     * @param \stdClass $cm Course module record.
     * @param context_module $context Module context.
     * @param int $groupid Effective group ID.
     */
    private static function require_group_access(\stdClass $cm, context_module $context, int $groupid): void {
        if (has_capability('moodle/site:accessallgroups', $context)) {
            return;
        }

        $allowedgroups = groups_get_activity_allowed_groups($cm);
        if ($groupid === 0) {
            if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS) {
                throw new \required_capability_exception(
                    $context,
                    'moodle/site:accessallgroups',
                    'nopermissions',
                    ''
                );
            }
            return;
        }
        if (!isset($allowedgroups[$groupid])) {
            throw new \required_capability_exception(
                $context,
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
    }
}
