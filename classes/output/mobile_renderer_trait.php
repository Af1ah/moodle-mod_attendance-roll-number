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

namespace mod_attendance\output;

defined('MOODLE_INTERNAL') || die();

use mod_attendance_take_page_params;
use mod_attendance_sessions_page_params;
use mod_attendance_structure;
use html_writer;
use html_table;
use html_table_row;
use html_table_cell;
use single_select;
use context_module;
use stdClass;

/**
 * Mobile-first renderer methods for the Attendance activity.
 *
 * Provides modern, mobile-first, touch-friendly UI for attendance sessions,
 * dynamic status color calculation, perfectly aligned columns, responsive controls,
 * and seamless compatibility with standard form submissions.
 *
 * @package    mod_attendance
 * @copyright  2026 Ariise LMS & ERP Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait mobile_renderer_trait {

    /**
     * Get dynamic, high-contrast color scheme for any custom status acronym/word.
     *
     * @param stdClass $st Status object
     * @param int $index Position in status list
     * @return array Array with keys 'bg', 'color', 'border'
     */
    protected function get_status_color_scheme($st, int $index = 0): array {
        $acr = strtoupper(trim($st->acronym ?? ''));
        $desc = strtolower(trim($st->description ?? ''));

        // 1. Present / Positive
        if ($acr === 'P' || $acr === 'PR' || $acr === 'PRE' || str_contains($desc, 'present')) {
            return ['bg' => '#198754', 'color' => '#ffffff', 'border' => '#198754'];
        }
        // 2. Absent / Negative
        if ($acr === 'A' || $acr === 'AB' || $acr === 'ABS' || str_contains($desc, 'absent')) {
            return ['bg' => '#dc3545', 'color' => '#ffffff', 'border' => '#dc3545'];
        }
        // 3. Late
        if ($acr === 'L' || $acr === 'LA' || $acr === 'LAT' || str_contains($desc, 'late') || str_contains($desc, 'tardy')) {
            return ['bg' => '#fd7e14', 'color' => '#ffffff', 'border' => '#fd7e14'];
        }
        // 4. Excused / On Duty / Leave / Medical
        if ($acr === 'E' || $acr === 'EX' || $acr === 'OD' || $acr === 'ML' || $acr === 'LV' ||
            str_contains($desc, 'excused') || str_contains($desc, 'duty') ||
            str_contains($desc, 'leave') || str_contains($desc, 'medical')) {
            return ['bg' => '#0dcaf0', 'color' => '#000000', 'border' => '#0dcaf0'];
        }
        // 5. Half day
        if ($acr === 'HD' || str_contains($desc, 'half')) {
            return ['bg' => '#6f42c1', 'color' => '#ffffff', 'border' => '#6f42c1'];
        }

        // 6. Dynamic fallback palette for custom words
        $palette = [
            ['bg' => '#198754', 'color' => '#ffffff', 'border' => '#198754'],
            ['bg' => '#dc3545', 'color' => '#ffffff', 'border' => '#dc3545'],
            ['bg' => '#fd7e14', 'color' => '#ffffff', 'border' => '#fd7e14'],
            ['bg' => '#0dcaf0', 'color' => '#000000', 'border' => '#0dcaf0'],
            ['bg' => '#6f42c1', 'color' => '#ffffff', 'border' => '#6f42c1'],
            ['bg' => '#20c997', 'color' => '#ffffff', 'border' => '#20c997'],
            ['bg' => '#d63384', 'color' => '#ffffff', 'border' => '#d63384'],
            ['bg' => '#0d6efd', 'color' => '#ffffff', 'border' => '#0d6efd'],
        ];

        return $palette[$index % count($palette)];
    }

    /**
     * Sort statuses such that highest value (Present) comes first,
     * lowest value (Absent) comes second, followed by all remaining statuses.
     *
     * @param array $statuses
     * @return array
     */
    protected function sort_statuses_by_priority(array $statuses): array {
        if (count($statuses) <= 2) {
            return array_values($statuses);
        }

        $list = array_values($statuses);
        $highest = null;
        $lowest = null;

        foreach ($list as $st) {
            $acr = strtoupper(trim($st->acronym ?? ''));
            $desc = strtolower(trim($st->description ?? ''));

            if ($acr === 'P' || $acr === 'PR' || str_contains($desc, 'present')) {
                if ($highest === null) {
                    $highest = $st;
                }
            } else if ($acr === 'A' || $acr === 'AB' || str_contains($desc, 'absent')) {
                if ($lowest === null) {
                    $lowest = $st;
                }
            }
        }

        if ($highest === null) {
            $highest = $list[0];
        }
        if ($lowest === null && count($list) > 1) {
            $lowest = $list[count($list) - 1];
        }

        $ordered = [];
        if ($highest) {
            $ordered[] = $highest;
        }
        if ($lowest && $lowest->id != $highest->id) {
            $ordered[] = $lowest;
        }

        foreach ($list as $st) {
            if ($highest && $st->id == $highest->id) {
                continue;
            }
            if ($lowest && $st->id == $lowest->id) {
                continue;
            }
            $ordered[] = $st;
        }

        return $ordered;
    }

    /**
     * Render take data view with modern card layout and responsive take list.
     *
     * @param take_data $takedata
     * @return string HTML
     */
    protected function render_take_data(take_data $takedata) {
        $controls = $this->render_attendance_take_controls($takedata);
        $ismodernlist = $takedata->pageparams->viewmode == mod_attendance_take_page_params::SORTED_LIST;

        $table = html_writer::start_div('attendance-table-container no-overflow');
        if ($ismodernlist) {
            $table .= $this->render_attendance_take_list($takedata);
        } else {
            $table .= $this->render_attendance_take_grid($takedata);
        }

        $table .= html_writer::input_hidden_params($takedata->url([
            'sesskey' => sesskey(),
            'page' => $takedata->pageparams->page,
            'perpage' => $takedata->pageparams->perpage,
        ]));
        $table .= html_writer::end_div();

        // Determine if there is a next page
        $group = 0;
        if ($takedata->pageparams->grouptype != mod_attendance_structure::SESSION_COMMON) {
            $group = $takedata->pageparams->grouptype;
        } else if (!empty($takedata->pageparams->group)) {
            $group = $takedata->pageparams->group;
        }

        $totalusers = count_enrolled_users(context_module::instance($takedata->cm->id), 'mod/attendance:canbelisted', $group);
        $usersperpage = (int)($takedata->pageparams->perpage ?? 0);
        $curpage = (int)($takedata->pageparams->page ?? 1);

        $hasNextPage = false;
        if ($usersperpage > 0 && $totalusers > 0 && $curpage > 0) {
            $numberofpages = (int)ceil($totalusers / $usersperpage);
            if ($curpage < $numberofpages) {
                $hasNextPage = true;
            }
        }

        $saveBtnLabel = $hasNextPage ? get_string('saveandshownext', 'attendance') : get_string('save');

        // Submit button
        $savebtn = html_writer::empty_tag('input', [
            'type'  => 'submit',
            'id'    => 'attendance-save-btn',
            'class' => 'btn btn-primary btn-lg shadow-sm px-4',
            'value' => $saveBtnLabel,
        ]);
        $table .= html_writer::div($savebtn, 'attendance-submit-bar text-center my-4');

        $form = html_writer::tag('form', $table, [
            'method' => 'post',
            'action' => $takedata->url_path(),
            'id' => 'attendancetakeform',
            'class' => 'attendance-take-form',
        ]);

        // Keep the existing grid mode independent from the list-only interaction module.
        if (!$ismodernlist) {
            return $controls . $form;
        }

        // Moodle core/modal receives renderer-created content and provides focus management.
        $summarytitle = html_writer::div(
            get_string('attendancesummary', 'attendance') .
            (!empty($takedata->sessioninfo->sessdate) ? html_writer::span(
                userdate($takedata->sessioninfo->sessdate, get_string('strftimedate')),
                'd-block text-muted small'
            ) : '')
        );
        $summaryfooter = html_writer::div(
            html_writer::tag('button', get_string('cancel'), [
                'type' => 'button',
                'class' => 'btn btn-sm btn-outline-secondary px-3',
                'data-action' => 'hide',
            ]) . html_writer::tag('button', get_string('save'), [
                'type' => 'button',
                'id' => 'attendanceConfirmSaveBtn',
                'class' => 'btn btn-sm btn-primary px-3 fw-semibold',
            ]),
            'd-flex justify-content-end gap-2'
        );

        $sortedstatuses = $this->sort_statuses_by_priority($takedata->statuses);
        $quickmodal = $this->render_quick_attendance_modal($takedata, $sortedstatuses);

        $modalconfig = html_writer::start_div('', [
            'id' => 'att-take-config',
            'hidden' => 'hidden',
            'data-cmid' => (int)$takedata->cm->id,
            'data-sessionid' => (int)$takedata->pageparams->sessionid,
            'data-grouptype' => (int)$takedata->pageparams->grouptype,
            'data-groupid' => (int)($takedata->pageparams->group ?? 0),
            'data-manageurl' => $takedata->att->url_manage()->out(false),
            'data-label-markattendance' => get_string('markattendance', 'attendance'),
            'data-label-pagetotal' => get_string('pagetotal', 'attendance'),
            'data-label-setallto' => get_string('setallto', 'attendance', '##STATUS##'),
            'data-label-unmarked' => get_string('unmarked', 'attendance'),
            'data-label-studentalreadyadded' => get_string('studentalreadyadded', 'attendance', '##ID##'),
            'data-label-pleaseenteridnumber' => get_string('pleaseenteridnumber', 'attendance'),
            'data-label-nostudentsselected' => get_string('nostudentsselected', 'attendance'),
            'data-label-removestudent' => get_string('removestudent', 'attendance'),
        ]);
        $modalconfig .= html_writer::tag('template', $summarytitle, ['id' => 'att-summary-title-template']);
        $modalconfig .= html_writer::tag('template', $summaryfooter, ['id' => 'att-summary-footer-template']);
        $modalconfig .= html_writer::tag('template', $quickmodal['title'], ['id' => 'att-quick-title-template']);
        $modalconfig .= html_writer::tag('template', $quickmodal['body'], ['id' => 'att-quick-body-template']);
        $modalconfig .= html_writer::tag('template', $quickmodal['footer'], ['id' => 'att-quick-footer-template']);
        $modalconfig .= html_writer::end_div();

        $this->page->requires->js_call_amd('mod_attendance/attendance_take', 'init');

        return $controls . $form . $modalconfig;
    }

    /**
     * Render Quick Attendance Modal with student search, status dropdowns, and confirmation.
     *
     * @param take_data $takedata
     * @param array $sortedstatuses Ordered status records.
     * @return array
     */
    protected function render_quick_attendance_modal(
        take_data $takedata,
        array $sortedstatuses
    ): array {
        $quicktitle = get_string('quickattendance', 'attendance');
        if (!empty($takedata->sessioninfo->sessdate)) {
            $quicktitle .= html_writer::span(
                userdate($takedata->sessioninfo->sessdate, get_string('strftimedate')),
                'd-block text-muted small'
            );
        }

        // Keep the client's standard default: selected students are Absent.
        $defaultabsentid = null;
        foreach ($sortedstatuses as $st) {
            $acr = strtoupper(trim($st->acronym ?? ''));
            $desc = strtolower(trim($st->description ?? ''));
            if ($defaultabsentid === null && ($acr === 'A' || $acr === 'AB' || str_contains($desc, 'absent'))) {
                $defaultabsentid = $st->id;
            }
        }
        if ($defaultabsentid === null && !empty($sortedstatuses)) {
            $defaultabsentid = count($sortedstatuses) > 1 ? $sortedstatuses[1]->id : $sortedstatuses[0]->id;
        }

        // Status options for selected students (default: Absent).
        $selectedopts = '';
        foreach ($sortedstatuses as $st) {
            $attributes = ['value' => $st->id];
            if ($st->id == $defaultabsentid) {
                $attributes['selected'] = 'selected';
            }
            $selectedopts .= html_writer::tag(
                'option',
                s($st->description ?: $st->acronym) . ' (' . s($st->acronym) . ')',
                $attributes
            );
        }

        $quickstatusrow = html_writer::div(
            html_writer::div(
                html_writer::tag('label', get_string('statusforselected', 'attendance'), [
                    'for' => 'att-quick-selected-status',
                    'class' => 'form-label fw-bold text-dark small mb-1',
                ]) .
                html_writer::tag('select', $selectedopts, [
                    'id' => 'att-quick-selected-status',
                    'class' => 'form-select form-control form-control-sm',
                ]),
                'col-12 mb-2'
            ),
            'row mb-2'
        );

        $quicksearchbox = html_writer::div(
            html_writer::tag('label', get_string('enteridnumber', 'attendance'), [
                'for' => 'att-quick-idnumber-input',
                'class' => 'form-label fw-bold text-dark small mb-1',
            ]) .
            html_writer::div(
                html_writer::empty_tag('input', [
                    'type' => 'text',
                    'id' => 'att-quick-idnumber-input',
                    'class' => 'form-control',
                    'placeholder' => get_string('enteridnumberplaceholder', 'attendance'),
                    'autocomplete' => 'off',
                ]) .
                html_writer::tag('button',
                    '<i class="fa fa-plus mr-1 me-1"></i> ' . get_string('addstudent', 'attendance'),
                    [
                        'type' => 'button',
                        'id' => 'att-quick-add-btn',
                        'class' => 'btn btn-primary px-3',
                    ]
                ),
                'input-group'
            ) .
            html_writer::div('', 'alert alert-danger py-2 px-3 mt-2 d-none small', [
                'id' => 'att-quick-error',
                'role' => 'alert',
            ]),
            'mb-3'
        );

        $quickselectedheader = html_writer::div(
            html_writer::tag('span', get_string('selectedstudents', 'attendance'), ['class' => 'fw-bold text-dark small']) .
            html_writer::span('0', 'badge bg-primary badge-primary ms-2 ml-2', ['id' => 'att-quick-selected-count']),
            'd-flex justify-content-between align-items-center mb-1'
        );

        $quickselectedlist = html_writer::div(
            html_writer::div(
                get_string('nostudentsselected', 'attendance'),
                'text-muted text-center py-3 small',
                ['id' => 'att-quick-empty-msg']
            ) .
            html_writer::div('', '', ['id' => 'att-quick-items-container']),
            'att-quick-students-box border rounded bg-white p-2 mb-2',
            ['id' => 'att-quick-selected-list']
        );

        $quickbody = html_writer::div(
            $quickstatusrow . $quicksearchbox . $quickselectedheader . $quickselectedlist,
            'att-quick-modal-body'
        );

        $quickfooter = html_writer::div(
            html_writer::tag('button', get_string('cancel'), [
                'type' => 'button',
                'class' => 'btn btn-sm btn-outline-secondary px-3',
                'data-action' => 'hide',
            ]) .
            html_writer::tag('button',
                '<i class="fa fa-check mr-1 me-1"></i> ' . get_string('saveattendance', 'attendance'),
                [
                    'type' => 'button',
                    'id' => 'att-quick-confirm-save-btn',
                    'class' => 'btn btn-sm btn-primary px-3 fw-semibold',
                ]
            ),
            'd-flex justify-content-end gap-2'
        );

        return [
            'title' => $quicktitle,
            'body' => $quickbody,
            'footer' => $quickfooter,
        ];
    }

    /**
     * Render take controls banner with quick attendance button and copy controls.
     *
     * @param take_data $takedata
     * @return string HTML
     */
    protected function render_attendance_take_controls(take_data $takedata) {
        $sess = $takedata->sessioninfo;
        $date = userdate($sess->sessdate, get_string('strftimedate'));
        $starttime = attendance_strftimehm($sess->sessdate);
        $endtime = attendance_strftimehm($sess->sessdate + $sess->duration);
        $timestr = $starttime . ($sess->duration > 0 ? ' - ' . $endtime : '');

        $card = html_writer::start_div('card border-0 shadow-sm mb-4 takecontrols att-mobile-enhanced w-100 overflow-hidden');
        $cardbodyclasses = 'card-body p-3';
        $card .= html_writer::start_div($cardbodyclasses);

        // Session Information Left Block
        $info = html_writer::start_div('session-info');
        $info .= html_writer::tag(
            'h5',
            '<i class="fa fa-calendar-check-o text-primary mr-2 me-2"></i> ' . $date,
            ['class' => 'card-title mb-1 font-weight-bold']
        );
        $info .= html_writer::div('<i class="fa fa-clock-o text-muted mr-1 me-1"></i> ' . $timestr, 'text-muted small mb-1');
        if (!empty($sess->description)) {
            $info .= html_writer::div(format_text($sess->description), 'text-muted small');
        }
        $info .= html_writer::end_div();

        // Action controls: Quick Attendance and Copy from previous.
        $quickbtn = html_writer::tag(
            'button',
            '<i class="fa fa-calendar mr-1 me-1" aria-hidden="true"></i> ' . get_string('quick', 'attendance'),
            [
                'type' => 'button',
                'id' => 'att-quick-attendance-btn',
                'class' => 'btn btn-outline-primary btn-sm font-weight-bold shadow-sm att-quick-btn',
                'title' => get_string('quickattendance', 'attendance'),
                'aria-label' => get_string('quickattendance', 'attendance'),
            ]
        );

        $actions = html_writer::start_div('take-controls-actions d-flex flex-wrap align-items-center justify-content-end gap-2');
        $actions .= $quickbtn;

        if (isset($takedata->sessions4copy) && count($takedata->sessions4copy) > 0) {
            $copyoptions = [];
            foreach ($takedata->sessions4copy as $s) {
                $sstart = attendance_strftimehm($s->sessdate);
                $send = $s->duration ? ' - ' . attendance_strftimehm($s->sessdate + $s->duration) : '';
                $copyoptions[$s->id] = $sstart . $send;
            }
            $select = new single_select(
                $takedata->url([], ['copyfrom']),
                'copyfrom',
                $copyoptions,
                $takedata->pageparams->copyfrom ?? null
            );
            $select->set_label(get_string('copyfrom', 'attendance'));
            $select->class = 'singleselect inline d-inline-block';
            $actions .= $this->output->render($select);
        }
        $actions .= html_writer::end_div();

        $headerrow = html_writer::div($info . $actions, 'd-flex justify-content-between align-items-start gap-2 w-100 flex-wrap');

        $card .= $headerrow;
        $card .= html_writer::end_div(); // card-body
        $card .= html_writer::end_div(); // card

        return $card;
    }

    /**
     * Render take list table with perfectly aligned 3-column structure.
     *
     * @param take_data $takedata
     * @return string HTML
     */
    protected function render_attendance_take_list(take_data $takedata) {
        global $CFG;

        $sortedStatuses = $this->sort_statuses_by_priority($takedata->statuses);
        $statusespayload = [];
        $idx = 0;
        foreach ($sortedStatuses as $st) {
            $statusespayload[] = [
                'id' => (string)$st->id,
                'acronym' => $st->acronym,
                'description' => $st->description,
                'colors' => $this->get_status_color_scheme($st, $idx++),
            ];
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable takelist table-hover att-theme-native ' .
            'attendance-single-status align-middle w-100';
        $table->attributes['data-theme-rendered'] = '1';
        $table->attributes['data-statuses'] = json_encode($statusespayload);

        // Extra user identity fields. Keep ID number first when it is configured for display.
        $extrasearchfields = [];
        if (!empty($CFG->showuseridentity) && has_capability('moodle/site:viewuseridentity', $takedata->att->context)) {
            $extrasearchfields = explode(',', $CFG->showuseridentity);
        }
        $showidnumber = in_array('idnumber', $extrasearchfields, true);
        $extrasearchfields = array_values(array_diff($extrasearchfields, ['idnumber']));

        $table->head = [];
        $table->align = [];
        $table->size = [];

        if ($showidnumber) {
            $sortdirection = $takedata->pageparams->sort == ATT_SORT_IDNUMBER_ASC ?
                ATT_SORT_IDNUMBER_DESC : ATT_SORT_IDNUMBER_ASC;
            $sortindicator = '';
            if ($takedata->pageparams->sort == ATT_SORT_IDNUMBER_ASC) {
                $sortindicator = html_writer::span('&#8593;', 'att-sort-indicator', ['aria-hidden' => 'true']);
            } else if ($takedata->pageparams->sort == ATT_SORT_IDNUMBER_DESC) {
                $sortindicator = html_writer::span('&#8595;', 'att-sort-indicator', ['aria-hidden' => 'true']);
            }
            $idnumberlabel = \core_user\fields::get_display_name('idnumber');
            $idnumberlink = html_writer::link(
                $takedata->url(['sort' => $sortdirection, 'page' => 1]),
                html_writer::span('ID NO', 'att-idnumber-label') . $sortindicator,
                [
                    'class' => 'att-idnumber-sort',
                    'title' => $idnumberlabel,
                    'aria-label' => $idnumberlabel,
                ]
            );
            $idnumberhead = new html_table_cell($idnumberlink);
            $idnumberhead->attributes['class'] = 'att-idnumber-col';
            $table->head[] = $idnumberhead;
            $table->align[] = 'left';
            $table->size[] = '1%';
        }

        $fullnamehead = new html_table_cell(get_string('fullname'));
        $fullnamehead->attributes['class'] = 'att-name-col';
        $table->head[] = $fullnamehead;
        $table->align[] = 'left';
        $table->size[] = '';

        foreach ($extrasearchfields as $field) {
            $extrahead = new html_table_cell(\core_user\fields::get_display_name($field));
            $extrahead->attributes['class'] = 'att-extra-identity-col';
            $table->head[] = $extrahead;
            $table->align[] = 'left';
            $table->size[] = '';
        }

        // Single Status column header
        $statushead = new html_table_cell(get_string('status', 'attendance'));
        $statushead->attributes['class'] = 'att-status-col';
        $table->head[] = $statushead;
        $table->align[] = 'center';
        $table->size[] = '1%';

        // Remarks header
        $table->head[] = get_string('remarks', 'attendance');
        $table->align[] = 'left';
        $table->size[] = '1%';

        // Set All Row
        $setallrow = new html_table_row();
        $setallrow->attributes['class'] = 'setallstatusesrow table-light';

        if ($showidnumber) {
            $idnumbersetallcell = new html_table_cell('');
            $idnumbersetallcell->attributes['class'] = 'att-idnumber-col';
            $setallrow->cells[] = $idnumbersetallcell;
        }

        $setallcell = new html_table_cell(html_writer::div(
            '<strong>' . get_string('setallstatuses', 'attendance') . '</strong>',
            'd-flex align-items-center'
        ));
        $setallcell->attributes['class'] = 'att-name-col';
        $setallrow->cells[] = $setallcell;

        foreach ($extrasearchfields as $field) {
            $extracell = new html_table_cell('');
            $extracell->attributes['class'] = 'att-extra-identity-col';
            $setallrow->cells[] = $extracell;
        }

        // Set all cycling button + hidden radio inputs embedded together
        $firstStatus = !empty($sortedStatuses) ? reset($sortedStatuses) : null;
        $setallbtn = html_writer::tag('button', get_string('setallto', 'attendance', $firstStatus ? $firstStatus->acronym : '?'), [
            'type' => 'button',
            'id' => 'att-mobile-setall',
            'class' => 'btn btn-sm btn-secondary att-mobile-setall-btn font-weight-bold',
            'title' => get_string('setallstatuses', 'attendance'),
            'data-current-cycle' => 0,
        ]);

        $hiddenSetAll = '';
        foreach ($sortedStatuses as $st) {
            $hiddenSetAll .= html_writer::empty_tag('input', [
                'id' => 'radiocheckstatus' . $st->id,
                'type' => 'radio',
                'name' => 'setallstatuses',
                'class' => 'st' . $st->id . ' d-none',
                'title' => $st->description,
            ]);
        }

        $setallcellbtn = new html_table_cell($setallbtn . $hiddenSetAll);
        $setallcellbtn->attributes['class'] = 'text-center att-status-col';
        $setallrow->cells[] = $setallcellbtn;

        $setallremarks = new html_table_cell('');
        $setallremarks->attributes['class'] = 'att-remarks-col';
        $setallrow->cells[] = $setallremarks;
        $table->data[] = $setallrow;

        // User rows
        foreach ($takedata->users as $user) {
            $row = new html_table_row();

            if ($showidnumber) {
                $idnumbercell = new html_table_cell(s($user->idnumber ?? ''));
                $idnumbercell->attributes['class'] = 'att-idnumber-col';
                $row->cells[] = $idnumbercell;
            }

            // Name + Avatar
            $fullname = html_writer::link($takedata->url_view(['studentid' => $user->id]), fullname($user), [
                'class' => 'font-weight-bold text-dark text-decoration-none',
            ]);
            $userdisplay = html_writer::div(
                $this->user_picture($user, ['size' => 35, 'class' => 'rounded-circle me-2']) . $fullname,
                'd-flex align-items-center'
            );
            $namecell = new html_table_cell($userdisplay);
            $namecell->attributes['class'] = 'att-name-col';
            $row->cells[] = $namecell;

            foreach ($extrasearchfields as $field) {
                $extracell = new html_table_cell(s($user->$field ?? ''));
                $extracell->attributes['class'] = 'att-extra-identity-col';
                $row->cells[] = $extracell;
            }

            // Determine selected status
            $selectedStatusId = null;
            $selectedStatusObj = null;
            if (array_key_exists($user->id, $takedata->sessionlog)) {
                $selectedStatusId = $takedata->sessionlog[$user->id]->statusid;
            }

            foreach ($sortedStatuses as $st) {
                if ($selectedStatusId && $st->id == $selectedStatusId) {
                    $selectedStatusObj = $st;
                    break;
                }
            }

            // Single cycling status button
            $statusIndex = -1;
            $idxCounter = 0;
            foreach ($sortedStatuses as $st) {
                if ($selectedStatusObj && $st->id == $selectedStatusObj->id) {
                    $statusIndex = $idxCounter;
                    break;
                }
                $idxCounter++;
            }

            $unmarkedcolors = ['bg' => '#f8f9fa', 'color' => '#6c757d', 'border' => '#ced4da'];
            $colors = $selectedStatusObj ?
                $this->get_status_color_scheme($selectedStatusObj, $statusIndex) : $unmarkedcolors;
            $borderStyle = $selectedStatusObj ? 'solid' : 'dashed';
            $btnText = $selectedStatusObj ? $selectedStatusObj->acronym : '?';
            $btnTitle = $selectedStatusObj ? $selectedStatusObj->description : get_string('markattendance', 'attendance');

            $buttonstyle = 'background-color:' . $colors['bg'] . '; color:' . $colors['color'] .
                '; border-color:' . $colors['border'] . '; border-style:' . $borderStyle . ';';
            $cyclebtn = html_writer::tag('button', $btnText, [
                'type' => 'button',
                'class' => 'att-mobile-cycle-btn',
                'data-radio-name' => 'user' . $user->id,
                'data-current-index' => $statusIndex,
                'title' => $btnTitle,
                'aria-label' => $btnTitle,
                'style' => $buttonstyle,
            ]);

            // Embed hidden radio inputs inside the status cell directly
            $hiddenradios = '';
            foreach ($takedata->statuses as $st) {
                $radioparams = [
                    'type'  => 'radio',
                    'name'  => 'user' . $user->id,
                    'class' => 'st' . $st->id . ' d-none',
                    'value' => $st->id,
                ];
                if ($selectedStatusId && $st->id == $selectedStatusId) {
                    $radioparams['checked'] = 'checked';
                }
                $hiddenradios .= html_writer::empty_tag('input', $radioparams);
            }

            $btncell = new html_table_cell($cyclebtn . $hiddenradios);
            $btncell->attributes['class'] = 'text-center att-mobile-status-cell att-status-col';
            $row->cells[] = $btncell;

            // Remarks text input
            $remarkVal = '';
            if (array_key_exists($user->id, $takedata->sessionlog)) {
                $remarkVal = $takedata->sessionlog[$user->id]->remarks ?? '';
            }
            $remarkInput = html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'remarks' . $user->id,
                'value' => $remarkVal,
                'class' => 'form-control form-control-sm remarks-input',
                'placeholder' => get_string('remarks', 'attendance'),
                'maxlength' => 255,
            ]);
            $remarkscell = new html_table_cell($remarkInput);
            $remarkscell->attributes['class'] = 'att-remarks-col';
            $row->cells[] = $remarkscell;

            $table->data[] = $row;
        }

        return html_writer::div(html_writer::table($table), 'table-responsive shadow-sm rounded');
    }

    /**
     * Render session manage data view for Star Theme with Tile Card listing.
     *
     * @param manage_data $sessdata
     * @return string HTML
     */
    protected function render_manage_data(manage_data $sessdata) {
        $tiles = $this->render_sess_manage_table($sessdata);
        $control = $this->render_sess_manage_control($sessdata);

        $formcontent = html_writer::div($tiles . $control, 'attendance-manage-tiles-wrapper');
        $o = html_writer::tag('form', $formcontent, [
            'method' => 'post',
            'action' => $sessdata->url_sessions()->out(),
            'id' => 'attendancemanageform',
            'class' => 'attendance-manage-form',
        ]);

        return html_writer::div($o, 'attsessions_manage_table w-100');
    }

    /**
     * Render session manage view as modern tile cards with one row per session.
     * Clicking a card opens Take/Change attendance, with Edit & Delete in the 3-dots menu.
     *
     * @param manage_data $sessdata
     * @return string HTML
     */
    protected function render_sess_manage_table(manage_data $sessdata) {
        $this->page->requires->js_init_call('M.mod_attendance.init_manage');

        if (empty($sessdata->sessions)) {
            return html_writer::div(
                html_writer::tag('i', '', ['class' => 'fa fa-calendar-times-o fa-3x text-muted mb-3']) .
                html_writer::tag('h5', get_string('nothingtodisplay'), ['class' => 'text-muted']),
                'card border-0 shadow-sm p-5 text-center bg-white rounded-3 my-3'
            );
        }

        $canmanage = has_capability('mod/attendance:manageattendances', $sessdata->att->context);
        $cantake = has_capability('mod/attendance:takeattendances', $sessdata->att->context);
        $canchange = has_capability('mod/attendance:changeattendances', $sessdata->att->context);

        // Preserve session custom fields from the standard table renderer.
        $customfields = [];
        $customfieldsdata = [];
        $handler = \mod_attendance\customfield\session_handler::create();
        $customfields = $handler->get_fields_for_display(reset($sessdata->sessions)->id);
        if (!empty($customfields)) {
            $customfieldsdata = $handler->get_instances_data(array_keys($sessdata->sessions));
        }

        $this->page->requires->js_call_amd('mod_attendance/sessions', 'init');

        $output = '';

        // Select All Toolbar
        $cblabel = '';
        if ($canmanage) {
            $cb = html_writer::checkbox('cb_selector', 0, false, '', [
                'id' => 'cb_selector',
                'class' => 'form-check-input me-2',
            ]);
            $cblabel = html_writer::label(
                $cb . '<span class="fw-semibold text-dark">' . get_string('selectall') . '</span>',
                'cb_selector',
                false,
                ['class' => 'd-flex align-items-center mb-0 cursor-pointer user-select-none']
            );
        }
        $countbadge = html_writer::span(
            count($sessdata->sessions) . ' ' . get_string('sessions', 'attendance'),
            'badge bg-primary-subtle text-primary rounded-pill px-3 py-2 fw-semibold'
        );
        $toolbarclasses = 'd-flex justify-content-between align-items-center bg-white rounded-3 ' .
            'p-3 mb-3 border shadow-sm';
        $toolbar = html_writer::div($cblabel . $countbadge, $toolbarclasses);

        $output .= $toolbar;

        // Session Cards Grid/List
        $tiles = html_writer::start_div('attendance-session-tiles d-flex flex-column gap-2 mb-3', ['data-theme-rendered' => '1']);

        foreach ($sessdata->sessions as $sess) {
            $datestr = userdate($sess->sessdate, get_string('strftimedmyw', 'attendance'));
            $starttime = attendance_strftimehm($sess->sessdate);
            $endtime = attendance_strftimehm($sess->sessdate + $sess->duration);
            $timestr = $starttime . ($sess->duration > 0 ? ' – ' . $endtime : '');

            $groupdeleted = !empty($sess->groupid) && empty($sessdata->groups[$sess->groupid]);
            $primaryurl = '';
            if (!$groupdeleted && (($sess->lasttaken > 0 && $canchange) || (empty($sess->lasttaken) && $cantake))) {
                $primaryurl = $sessdata->url_take($sess->id, $sess->groupid)->out(false);
            }

            // Group badge if applicable
            $groupBadge = '';
            if ($sess->groupid) {
                if (!empty($sessdata->groups[$sess->groupid])) {
                    $groupBadge = html_writer::span(
                        $sessdata->groups[$sess->groupid]->name,
                        'badge bg-light text-secondary border me-2'
                    );
                } else {
                    $groupBadge = html_writer::span(get_string('deletedgroup', 'attendance'), 'badge bg-danger-subtle border me-2');
                }
            }

            // Status Indicator (Only show if already taken)
            $statusHtml = '';
            if ($sess->lasttaken > 0) {
                $statusHtml = html_writer::span(
                    '✓ ' . get_string('eventtaken', 'attendance'),
                    'badge bg-light text-success border me-2'
                );
            }

            // Left: Checkbox
            $sesscb = '';
            if ($canmanage) {
                $sesscb = html_writer::checkbox('sessid[]', $sess->id, false, '', [
                    'class' => 'attendancesesscheckbox form-check-input mt-0',
                    'id' => 'sess_cb_' . $sess->id,
                ]);
            }
            $checkboxBlock = html_writer::div($sesscb, 'session-tile-checkbox d-flex align-items-center me-3 flex-shrink-0');

            // Center: Date, Time, Group, Description
            $dateHtml = html_writer::span($datestr, 'fw-semibold text-dark me-2');
            $timeHtml = html_writer::span($timestr, 'text-muted small');
            $topRow = html_writer::div(
                $dateHtml . '<span class="text-muted mx-1">·</span>' . $timeHtml,
                'd-flex align-items-center flex-wrap'
            );

            $bottomRow = '';
            if (!empty($sess->description)) {
                $bottomRow = html_writer::div(format_text($sess->description), 'text-muted small text-truncate mt-1');
            }

            $customfieldrow = '';
            foreach ($customfields as $field) {
                $fieldid = $field->get('id');
                if (!isset($customfieldsdata[$sess->id][$fieldid])) {
                    continue;
                }
                $value = $customfieldsdata[$sess->id][$fieldid]->get('value');
                if ($value === '' || $value === null) {
                    continue;
                }
                $customfieldrow .= html_writer::span(
                    html_writer::span($field->get_formatted_name() . ': ', 'fw-semibold') . s((string)$value),
                    'session-custom-field badge bg-light text-secondary border me-1 mt-1'
                );
            }
            if ($customfieldrow !== '') {
                $customfieldrow = html_writer::div($customfieldrow, 'session-custom-fields d-flex flex-wrap');
            }

            $contentBlock = html_writer::div($topRow . $bottomRow . $customfieldrow, 'session-tile-content flex-grow-1 min-w-0');

            // Right: 3-Dots Menu (Edit, QR/Password, Delete)
            $menuItems = '';

            // 1. QR Code / Password modal trigger
            if (!$groupdeleted && (!empty($sess->studentpassword) || ($sess->includeqrcode == 1)) &&
                ($canmanage || $cantake || $canchange)) {
                $icon = new password_icon($sess->studentpassword, $sess->id);
                $icon->includeqrcode = ($sess->includeqrcode == 1 || $sess->rotateqrcode == 1) ? 1 : 0;
                $menuItems .= html_writer::tag(
                    'li',
                    html_writer::div($this->render($icon), 'dropdown-item py-2 d-flex align-items-center')
                );
            }

            // 2. Edit session
            if ($canmanage && !$groupdeleted) {
                $editurl = $sessdata->url_sessions($sess->id, mod_attendance_sessions_page_params::ACTION_UPDATE)->out(false);
                $menuItems .= html_writer::tag('li', html_writer::link(
                    $editurl,
                    get_string('editsession', 'attendance'),
                    ['class' => 'dropdown-item py-2']
                ));

                // Divider
                $menuItems .= html_writer::tag('li', html_writer::empty_tag('hr', ['class' => 'dropdown-divider my-1']));

                // 3. Delete session
                $delurl = $sessdata->url_sessions($sess->id, mod_attendance_sessions_page_params::ACTION_DELETE)->out(false);
                $menuItems .= html_writer::tag('li', html_writer::link(
                    $delurl,
                    get_string('deletesession', 'attendance'),
                    ['class' => 'dropdown-item py-2 text-danger']
                ));
            }

            $dropdownWrapper = '';
            if ($menuItems !== '') {
                $dropdownMenu = html_writer::tag('ul', $menuItems, [
                    'class' => 'dropdown-menu dropdown-menu-end shadow border-0 rounded-3 p-1',
                ]);
                $dropdownBtn = html_writer::tag('button', '<i class="fa fa-ellipsis-v text-muted"></i>', [
                    'type' => 'button',
                    'class' => 'btn btn-sm btn-icon btn-light rounded-circle session-kebab-btn',
                    'data-bs-toggle' => 'dropdown',
                    'data-toggle' => 'dropdown',
                    'aria-expanded' => 'false',
                    'title' => get_string('actions'),
                ]);
                $dropdownWrapper = html_writer::div($dropdownBtn . $dropdownMenu, 'dropdown d-inline-block');
            }

            $rightBlock = html_writer::div(
                $groupBadge . $statusHtml . $dropdownWrapper,
                'session-tile-actions d-flex align-items-center flex-shrink-0 ms-3'
            );

            // Complete Minimalist Session Row
            $cardattributes = [];
            $cardclasses = 'attendance-session-card bg-white rounded-3 border p-3 d-flex align-items-center ' .
                'justify-content-between position-relative transition-all';
            if ($primaryurl !== '') {
                $cardattributes['data-primary-url'] = $primaryurl;
                $cardclasses .= ' cursor-pointer';
            }
            $card = html_writer::div(
                $checkboxBlock . $contentBlock . $rightBlock,
                $cardclasses,
                $cardattributes
            );

            $tiles .= $card;
        }

        $tiles .= html_writer::end_div(); // attendance-session-tiles
        $output .= $tiles;

        return $output;
    }

    /**
     * Render session manage control (bulk actions bottom bar).
     *
     * @param manage_data $sessdata
     * @return string HTML
     */
    protected function render_sess_manage_control(manage_data $sessdata) {
        $left = '';
        if ($sessdata->hiddensessionscount > 0 && has_capability('mod/attendance:manageattendances', $sessdata->att->context)) {
            $delhiddenbtn = html_writer::empty_tag('input', [
                'type' => 'submit',
                'name' => 'deletehiddensessions',
                'class' => 'btn btn-outline-danger btn-sm',
                'value' => get_string('deletehiddensessions', 'attendance'),
            ]);
            $left = $delhiddenbtn;
        }

        $right = '';
        if (has_capability('mod/attendance:manageattendances', $sessdata->att->context)) {
            $options = [
                mod_attendance_sessions_page_params::ACTION_DELETE_SELECTED => get_string('delete'),
                mod_attendance_sessions_page_params::ACTION_CHANGE_DURATION => get_string('changeduration', 'attendance'),
            ];
            $select = html_writer::select(
                $options,
                'action',
                '',
                ['' => get_string('choose') . '...'],
                ['class' => 'form-select form-select-sm d-inline-block w-auto me-2']
            );
            $okbtn = html_writer::empty_tag('input', [
                'type' => 'submit',
                'name' => 'ok',
                'value' => get_string('ok'),
                'class' => 'btn btn-primary btn-sm px-3 shadow-sm',
            ]);
            $right = html_writer::div($select . $okbtn, 'd-flex align-items-center gap-2');
        }

        $barclasses = 'attendance-manage-bulk-card bg-white rounded-3 border p-3 shadow-sm ' .
            'd-flex justify-content-between align-items-center flex-wrap gap-2 mt-3';
        $bar = html_writer::start_div($barclasses);
        $bar .= html_writer::div($left, 'manage-control-left');
        $bar .= html_writer::div($right, 'manage-control-right ms-auto');
        $bar .= html_writer::end_div();

        return $bar;
    }
}
