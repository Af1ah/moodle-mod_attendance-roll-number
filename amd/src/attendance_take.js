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

/**
 * Mobile attendance-taking interactions.
 *
 * Provides cycling status buttons with prioritized cycling order:
 * 1. Highest value (Present)
 * 2. Lowest value (Absent)
 * 3. Intermediate values (Late, Excused, On Duty, etc.)
 *
 * Includes pre-save Attendance Summary Modal before final form submission.
 *
 * @module     mod_attendance/attendance_take
 * @package    mod_attendance
 * @copyright  2026 Ariise LMS & ERP Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'jquery',
    'core/log',
    'core/ajax',
    'core/notification',
    'core/modal',
], function($, Log, Ajax, Notification, Modal) {

    /** @var {Array} Statuses array sorted by priority (Highest, Lowest, Others). */
    var statuses = [];

    /** @var {boolean} Confirmation flag for modal submission. */
    var isConfirmed = false;

    /** @var {Object} Map of selected student objects keyed by student ID. */
    var selectedStudents = {};

    /** @var {Object} Renderer configuration. */
    var settings = {};

    /** @var {Object|null} Active Quick Attendance core modal. */
    var quickModal = null;

    /** @var {Object} Localised interface labels supplied by the renderer. */
    var labels = {
        markattendance: 'Select attendance status',
        pagetotal: 'Students on this page',
        setallto: 'Set all: ##STATUS##',
        unmarked: 'Not marked',
        studentalreadyadded: 'Student with ID number "##ID##" has already been added.',
        pleaseenteridnumber: 'Please enter one or more student ID numbers.',
        nostudentsselected: 'No students added yet. Enter one or more ID numbers above and press Enter.',
        removestudent: 'Remove student',
    };

    /** @var {Object} Default unmarked styling. */
    var UNMARKED_COLOR = {bg: '#f8f9fa', color: '#6c757d', border: '#ced4da'};

    /** @var {Array} Fallback color palette. */
    var COLOR_PALETTE = [
        {bg: '#198754', color: '#ffffff', border: '#198754'}, // Green (Present)
        {bg: '#dc3545', color: '#ffffff', border: '#dc3545'}, // Red (Absent)
        {bg: '#fd7e14', color: '#ffffff', border: '#fd7e14'}, // Orange (Late)
        {bg: '#0dcaf0', color: '#000000', border: '#0dcaf0'}, // Cyan (Excused)
        {bg: '#6f42c1', color: '#ffffff', border: '#6f42c1'}, // Purple
        {bg: '#20c997', color: '#ffffff', border: '#20c997'},
        {bg: '#d63384', color: '#ffffff', border: '#d63384'},
        {bg: '#0d6efd', color: '#ffffff', border: '#0d6efd'},
    ];

    /**
     * Escape a value before adding it to summary HTML.
     *
     * @param {*} value
     * @return {string}
     */
    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    /**
     * Build the localised Set all label.
     *
     * @param {string} acronym
     * @return {string}
     */
    function setAllLabel(acronym) {
        return labels.setallto.replace('##STATUS##', acronym);
    }

    /**
     * Sort statuses such that:
     * 1st = Highest value / Present
     * 2nd = Lowest value / Absent
     * 3rd+ = Intermediate / Other statuses
     *
     * @param {Array} list
     * @return {Array}
     */
    function sortStatusesByPriority(list) {
        if (!Array.isArray(list) || list.length <= 2) {
            return list || [];
        }

        var highest = null;
        var lowest = null;

        for (var i = 0; i < list.length; i++) {
            var st = list[i];
            var acr = (st.acronym || '').toUpperCase().trim();
            var desc = (st.description || '').toLowerCase().trim();

            if (acr === 'P' || acr === 'PR' || acr === 'PRE' || desc.indexOf('present') !== -1) {
                if (!highest) {
                    highest = st;
                }
            } else if (acr === 'A' || acr === 'AB' || acr === 'ABS' || desc.indexOf('absent') !== -1) {
                if (!lowest) {
                    lowest = st;
                }
            }
        }

        if (!highest) {
            highest = list[0];
        }
        if (!lowest && list.length > 1) {
            lowest = list[list.length - 1];
        }

        var sorted = [];
        if (highest) {
            sorted.push(highest);
        }
        if (lowest && String(lowest.id) !== String(highest.id)) {
            sorted.push(lowest);
        }

        for (var j = 0; j < list.length; j++) {
            var item = list[j];
            if (highest && String(item.id) === String(highest.id)) {
                continue;
            }
            if (lowest && String(item.id) === String(lowest.id)) {
                continue;
            }
            sorted.push(item);
        }

        return sorted;
    }

    /**
     * Compute color scheme for a status acronym/description.
     *
     * @param {Object} status
     * @param {number} idx
     * @return {Object}
     */
    function computeStatusColor(status, idx) {
        if (!status) {
            return UNMARKED_COLOR;
        }
        var acr = (status.acronym || '').toUpperCase().trim();
        var desc = (status.description || '').toLowerCase().trim();

        if (acr === 'P' || acr === 'PR' || acr === 'PRE' || desc.indexOf('present') !== -1) {
            return {bg: '#198754', color: '#ffffff', border: '#198754'};
        }
        if (acr === 'A' || acr === 'AB' || acr === 'ABS' || desc.indexOf('absent') !== -1) {
            return {bg: '#dc3545', color: '#ffffff', border: '#dc3545'};
        }
        if (acr === 'L' || acr === 'LA' || acr === 'LAT' || desc.indexOf('late') !== -1 || desc.indexOf('tardy') !== -1) {
            return {bg: '#fd7e14', color: '#ffffff', border: '#fd7e14'};
        }
        if (acr === 'E' || acr === 'EX' || acr === 'OD' || acr === 'ML' || acr === 'LV' ||
            desc.indexOf('excused') !== -1 || desc.indexOf('duty') !== -1 ||
            desc.indexOf('leave') !== -1 || desc.indexOf('medical') !== -1) {
            return {bg: '#0dcaf0', color: '#000000', border: '#0dcaf0'};
        }
        if (acr === 'HD' || desc.indexOf('half') !== -1) {
            return {bg: '#6f42c1', color: '#ffffff', border: '#6f42c1'};
        }

        var pidx = (typeof idx === 'number' && idx >= 0) ? idx : 0;
        return COLOR_PALETTE[pidx % COLOR_PALETTE.length];
    }

    /**
     * Extract statuses from table data attribute or DOM radio buttons.
     *
     * @return {Array}
     */
    function extractStatuses() {
        var $table = $('table.takelist');
        if ($table.length === 0) {
            return [];
        }

        var rawData = $table.attr('data-statuses');
        if (rawData) {
            try {
                var parsed = (typeof rawData === 'string') ? JSON.parse(rawData) : rawData;
                if (Array.isArray(parsed) && parsed.length > 0) {
                    for (var p = 0; p < parsed.length; p++) {
                        if (!parsed[p].colors) {
                            parsed[p].colors = computeStatusColor(parsed[p], p);
                        }
                    }
                    return sortStatusesByPriority(parsed);
                }
            } catch (e) {
                Log.debug('Could not parse data-statuses attribute: ' + e);
            }
        }

        var extracted = [];
        var seen = {};
        $table.find('input[type="radio"]').each(function() {
            var $r = $(this);
            var val = $r.val();
            if (val && !seen[val]) {
                seen[val] = true;
                var title = $r.attr('title') || '';
                var acr = title || ('S' + val);
                extracted.push({
                    id: String(val),
                    acronym: acr,
                    description: title || acr,
                    colors: computeStatusColor({acronym: acr, description: title}, extracted.length)
                });
            }
        });

        return sortStatusesByPriority(extracted);
    }

    /**
     * Get status object by ID.
     *
     * @param {string|number} statusId
     * @return {Object|null}
     */
    function getStatusById(statusId) {
        for (var i = 0; i < statuses.length; i++) {
            if (String(statuses[i].id) === String(statusId)) {
                return statuses[i];
            }
        }
        return null;
    }

    /**
     * Get index of status by ID.
     *
     * @param {string|number} statusId
     * @return {number}
     */
    function getStatusIndex(statusId) {
        for (var i = 0; i < statuses.length; i++) {
            if (String(statuses[i].id) === String(statusId)) {
                return i;
            }
        }
        return -1;
    }

    /**
     * Apply style to a cycling button based on status.
     *
     * @param {jQuery} $button
     * @param {Object|null} status
     */
    function styleButton($button, status) {
        var scheme = status ? (status.colors || computeStatusColor(status, getStatusIndex(status.id))) : UNMARKED_COLOR;
        var borderStyle = status ? 'solid' : 'dashed';
        $button.css({
            'background-color': scheme.bg,
            'color': scheme.color,
            'border-color': scheme.border,
            'border-style': borderStyle,
        });
        $button.text(status ? status.acronym : '?');
        $button.attr('title', status ? status.description : labels.markattendance);
        $button.attr('aria-label', status ? status.description : labels.markattendance);
    }

    /**
     * Handle cycling button click.
     *
     * @param {Event} e
     */
    function onCycleClick(e) {
        e.preventDefault();
        e.stopPropagation();

        if (statuses.length === 0) {
            statuses = extractStatuses();
        }

        if (statuses.length === 0) {
            Log.error('mod_attendance: No statuses available to cycle.');
            return;
        }

        var $button = $(this);
        var radioName = $button.data('radio-name') || $button.attr('data-radio-name');
        var currentIndex = parseInt($button.data('current-index'), 10);
        if (isNaN(currentIndex)) {
            currentIndex = -1;
        }

        var nextIndex = (currentIndex < 0) ? 0 : ((currentIndex + 1) % statuses.length);
        var nextStatus = statuses[nextIndex];

        var $form = $('#attendancetakeform');
        var $cell = $button.closest('td');

        var $targetRadio = $cell.find('input[type="radio"][value="' + nextStatus.id + '"]');
        if ($targetRadio.length === 0 && radioName) {
            $targetRadio = $form.find('input[type="radio"][name="' + radioName + '"][value="' + nextStatus.id + '"]');
        }

        if ($targetRadio.length > 0) {
            $targetRadio.prop('checked', true).trigger('change');
        }

        $button.data('current-index', nextIndex);
        $button.attr('data-current-index', nextIndex);
        styleButton($button, nextStatus);

        $button.addClass('att-mobile-pulse');
        setTimeout(function() {
            $button.removeClass('att-mobile-pulse');
        }, 200);
    }

    /**
     * Handle Set All button click.
     *
     * @param {Event} e
     */
    function onSetAllClick(e) {
        e.preventDefault();

        if (statuses.length === 0) {
            statuses = extractStatuses();
        }

        if (statuses.length === 0) {
            return;
        }

        var $button = $(this);
        var cycleIndex = parseInt($button.data('current-cycle'), 10) || 0;
        var targetStatus = statuses[cycleIndex % statuses.length];

        var $form = $('#attendancetakeform');

        $('.att-mobile-cycle-btn').each(function() {
            var $btn = $(this);
            var radioName = $btn.data('radio-name') || $btn.attr('data-radio-name');
            var $cell = $btn.closest('td');

            var $radio = $cell.find('input[type="radio"][value="' + targetStatus.id + '"]');
            if ($radio.length === 0 && radioName) {
                $radio = $form.find('input[type="radio"][name="' + radioName + '"][value="' + targetStatus.id + '"]');
            }

            if ($radio.length > 0) {
                $radio.prop('checked', true);
            }

            $btn.data('current-index', getStatusIndex(targetStatus.id));
            $btn.attr('data-current-index', getStatusIndex(targetStatus.id));
            styleButton($btn, targetStatus);
        });

        var nextCycle = (cycleIndex + 1) % statuses.length;
        var nextStatus = statuses[nextCycle];
        $button.data('current-cycle', nextCycle);
        $button.text(setAllLabel(nextStatus.acronym));

        $('.att-mobile-cycle-btn').addClass('att-mobile-pulse');
        setTimeout(function() {
            $('.att-mobile-cycle-btn').removeClass('att-mobile-pulse');
        }, 250);
    }

    /**
     * Show Attendance Summary Modal before submitting the form.
     */
    function showSummaryModal() {
        if (statuses.length === 0) {
            statuses = extractStatuses();
        }

        var counts = {};
        for (var i = 0; i < statuses.length; i++) {
            counts[statuses[i].id] = 0;
        }

        var totalStudents = 0;
        var markedStudents = 0;

        $('.att-mobile-cycle-btn').each(function() {
            totalStudents++;
            var radioName = $(this).data('radio-name') || $(this).attr('data-radio-name');
            var $checked = $('input[type="radio"][name="' + radioName + '"]:checked');
            if ($checked.length > 0) {
                var val = $checked.val();
                if (counts[val] !== undefined) {
                    counts[val]++;
                    markedStudents++;
                }
            }
        });

        var unmarkedCount = totalStudents - markedStudents;

        var html = '<div class="attendance-summary-list">';
        for (var j = 0; j < statuses.length; j++) {
            var st = statuses[j];
            var cnt = counts[st.id] || 0;
            html += '<div class="d-flex justify-content-between align-items-center py-2 border-bottom">';
            html += '<span class="summary-item-label text-dark">' +
                escapeHtml(st.description || st.acronym) + '</span>';
            html += '<span class="summary-item-count text-dark">' + cnt + '</span>';
            html += '</div>';
        }

        if (unmarkedCount > 0) {
            html += '<div class="d-flex justify-content-between align-items-center py-2 border-bottom">';
            html += '<span class="summary-item-label text-muted">' + escapeHtml(labels.unmarked) + '</span>';
            html += '<span class="summary-item-count text-muted">' + unmarkedCount + '</span>';
            html += '</div>';
        }

        html += '<div class="d-flex justify-content-between align-items-center pt-3 mt-1">';
        html += '<span class="summary-total-label text-dark">' + escapeHtml(labels.pagetotal) + '</span>';
        html += '<span class="summary-total-count text-dark">' + totalStudents + '</span>';
        html += '</div>';
        html += '</div>';

        Modal.create({
            title: settings.summarytitle,
            body: html,
            footer: settings.summaryfooter,
            show: true,
            removeOnClose: true,
            isVerticallyCentered: true,
        }).then(function(modal) {
            modal.getRoot().addClass('att-summary-modal');
            modal.getRoot().on('click', '#attendanceConfirmSaveBtn', function() {
                isConfirmed = true;
                modal.hide();
                document.getElementById('attendancetakeform').submit();
            });
            return modal;
        }).catch(Notification.exception);
    }

    /**
     * Show or clear quick attendance error message.
     *
     * @param {string} msg
     */
    function showQuickError(msg) {
        var $err = $('#att-quick-error');
        if (msg) {
            $err.text(msg).removeClass('d-none');
        } else {
            $err.text('').addClass('d-none');
        }
    }

    /**
     * Render the list of selected students in the quick attendance modal.
     */
    function renderSelectedList() {
        var ids = Object.keys(selectedStudents);
        var count = ids.length;
        $('#att-quick-selected-count').text(count);

        var $container = $('#att-quick-items-container');
        var $emptyMsg = $('#att-quick-empty-msg');

        if (count === 0) {
            $emptyMsg.removeClass('d-none');
            $container.empty();
            return;
        }

        $emptyMsg.addClass('d-none');
        var html = '';
        for (var i = 0; i < ids.length; i++) {
            var student = selectedStudents[ids[i]];
            var displayId = student.idnumber ? student.idnumber : ('#' + student.id);
            html += '<div class="att-quick-student-item d-flex justify-content-between ' +
                'align-items-center py-1 px-2 border-bottom">';
            html += '<div class="d-flex align-items-center gap-2 text-truncate me-2">';
            html += '<span class="badge bg-light text-dark border font-monospace px-2 py-1">' + escapeHtml(displayId) + '</span>';
            html += '<span class="text-dark small fw-semibold text-truncate">' + escapeHtml(student.name) + '</span>';
            html += '</div>';
            html += '<button type="button" class="btn btn-sm btn-link text-danger p-0 att-quick-remove-btn" data-student-id="' +
                escapeHtml(student.id) + '" title="' + escapeHtml(labels.removestudent) + '" aria-label="' +
                escapeHtml(labels.removestudent) + '">';
            html += '<i class="fa fa-times" aria-hidden="true"></i>';
            html += '</button>';
            html += '</div>';
        }
        $container.html(html);
    }

    /**
     * Search and add students by comma- or line-separated ID numbers.
     *
     * @param {string} query
     */
    function addStudentsByIdNumbers(query) {
        var raw = $.trim(query);
        if (!raw) {
            showQuickError(labels.pleaseenteridnumber);
            $('#att-quick-idnumber-input').focus();
            return;
        }

        var $button = $('#att-quick-add-btn');
        $button.prop('disabled', true);
        var request = {
            methodname: 'mod_attendance_find_student_by_idnumber',
            args: {
                cmid: settings.cmid,
                sessionid: settings.sessionid,
                grouptype: settings.grouptype,
                groupid: settings.groupid,
                idnumber: raw,
            },
        };

        Ajax.call([request])[0].then(function(response) {
            var errors = [];
            response.students.forEach(function(student) {
                if (!student.found) {
                    errors.push(student.message);
                    return;
                }
                if (selectedStudents[student.id]) {
                    errors.push(labels.studentalreadyadded.replace('##ID##', student.idnumber));
                    return;
                }
                selectedStudents[student.id] = student;
            });

            showQuickError(errors.join(' '));
            $('#att-quick-idnumber-input').val('');
            renderSelectedList();
        }).catch(Notification.exception).then(function() {
            $button.prop('disabled', false);
            $('#att-quick-idnumber-input').focus();
            return null;
        });
    }

    /**
     * Create and show the Quick Attendance modal using Moodle's modal API.
     */
    function showQuickAttendanceModal() {
        selectedStudents = {};
        Modal.create({
            title: settings.quicktitle,
            body: settings.quickbody,
            footer: settings.quickfooter,
            large: true,
            show: true,
            removeOnClose: true,
            isVerticallyCentered: true,
        }).then(function(modal) {
            quickModal = modal;
            modal.getRoot().addClass('att-quick-modal');
            showQuickError('');
            renderSelectedList();
            $('#att-quick-idnumber-input').val('').focus();
            return modal;
        }).catch(Notification.exception);
    }

    /**
     * Read renderer-owned modal content without serialising it through js_call_amd.
     *
     * @param {string} id Template element ID.
     * @return {string}
     */
    function getTemplateContent(id) {
        var template = document.getElementById(id);
        return template ? template.innerHTML : '';
    }

    /**
     * Read scalar settings and localised labels from the page.
     *
     * @return {Object}
     */
    function getSettingsFromDom() {
        var config = document.getElementById('att-take-config');
        if (!config) {
            return {};
        }

        labels = $.extend({}, labels, {
            markattendance: config.getAttribute('data-label-markattendance'),
            pagetotal: config.getAttribute('data-label-pagetotal'),
            setallto: config.getAttribute('data-label-setallto'),
            unmarked: config.getAttribute('data-label-unmarked'),
            studentalreadyadded: config.getAttribute('data-label-studentalreadyadded'),
            pleaseenteridnumber: config.getAttribute('data-label-pleaseenteridnumber'),
            nostudentsselected: config.getAttribute('data-label-nostudentsselected'),
            removestudent: config.getAttribute('data-label-removestudent'),
        });

        return {
            cmid: Number(config.getAttribute('data-cmid')),
            sessionid: Number(config.getAttribute('data-sessionid')),
            grouptype: Number(config.getAttribute('data-grouptype')),
            groupid: Number(config.getAttribute('data-groupid')),
            manageurl: config.getAttribute('data-manageurl'),
            summarytitle: getTemplateContent('att-summary-title-template'),
            summaryfooter: getTemplateContent('att-summary-footer-template'),
            quicktitle: getTemplateContent('att-quick-title-template'),
            quickbody: getTemplateContent('att-quick-body-template'),
            quickfooter: getTemplateContent('att-quick-footer-template'),
        };
    }

    return {
        /**
         * Initialize attendance take interaction.
         */
        init: function() {
            $(document).ready(function() {
                settings = getSettingsFromDom();
                selectedStudents = {};

                if (statuses.length === 0) {
                    statuses = extractStatuses();
                }

                // Global event delegation for cycling buttons
                $(document).off('click', '.att-mobile-cycle-btn').on('click', '.att-mobile-cycle-btn', onCycleClick);

                // Global event delegation for Set All button
                var setAllSelector = '#att-mobile-setall, .att-mobile-setall-btn';
                $(document).off('click', setAllSelector).on('click', setAllSelector, onSetAllClick);

                // Open Quick Attendance modal.
                $(document).off('click', '#att-quick-attendance-btn').on('click', '#att-quick-attendance-btn', function(e) {
                    e.preventDefault();
                    showQuickAttendanceModal();
                });

                // Enter key in ID number input
                $(document).off('keydown', '#att-quick-idnumber-input').on('keydown', '#att-quick-idnumber-input', function(e) {
                    if (e.which === 13 || e.keyCode === 13) {
                        e.preventDefault();
                        e.stopPropagation();
                        addStudentsByIdNumbers($(this).val());
                    }
                });

                // Click Add button
                $(document).off('click', '#att-quick-add-btn').on('click', '#att-quick-add-btn', function(e) {
                    e.preventDefault();
                    addStudentsByIdNumbers($('#att-quick-idnumber-input').val());
                });

                // Click Remove student button
                $(document).off('click', '.att-quick-remove-btn').on('click', '.att-quick-remove-btn', function(e) {
                    e.preventDefault();
                    var sid = $(this).data('student-id');
                    if (sid && selectedStudents[sid]) {
                        delete selectedStudents[sid];
                        renderSelectedList();
                    }
                });

                // Save Quick Attendance with a compact, server-validated request.
                $(document).off('click', '#att-quick-confirm-save-btn').on('click', '#att-quick-confirm-save-btn', function() {
                    var $button = $(this);
                    $button.prop('disabled', true);
                    var request = {
                        methodname: 'mod_attendance_save_quick_attendance',
                        args: {
                            cmid: settings.cmid,
                            sessionid: settings.sessionid,
                            grouptype: settings.grouptype,
                            groupid: settings.groupid,
                            selectedstudentids: Object.keys(selectedStudents).map(Number),
                            selectedstatusid: Number($('#att-quick-selected-status').val()),
                        },
                    };

                    Ajax.call([request])[0].then(function() {
                        if (quickModal) {
                            quickModal.hide();
                        }
                        window.location.assign(settings.manageurl);
                    }).catch(function(error) {
                        $button.prop('disabled', false);
                        Notification.exception(error);
                    });
                });

                // Two-way synchronization: Listen to underlying radio changes
                var radioSelector = '#attendancetakeform input[type="radio"]';
                $(document).off('change', radioSelector).on('change', radioSelector, function() {
                    var $radio = $(this);
                    var radioName = $radio.attr('name');
                    if (!radioName) {
                        return;
                    }
                    var $btn = $('.att-mobile-cycle-btn[data-radio-name="' + radioName + '"]');
                    if ($btn.length > 0 && $radio.is(':checked')) {
                        var statusId = $radio.val();
                        var st = getStatusById(statusId);
                        $btn.data('current-index', getStatusIndex(statusId));
                        $btn.attr('data-current-index', getStatusIndex(statusId));
                        styleButton($btn, st);
                    }
                });

                // Intercept form submission to show Attendance Summary Modal
                $(document).off('submit', '#attendancetakeform').on('submit', '#attendancetakeform', function(e) {
                    if (!isConfirmed) {
                        e.preventDefault();
                        showSummaryModal();
                    }
                });

                Log.debug('mod_attendance: Attendance take handler active with summary modal.');
            });
        }
    };
});
