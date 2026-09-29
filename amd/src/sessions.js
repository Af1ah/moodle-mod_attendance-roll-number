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
 * Interactions for the responsive session-card list.
 *
 * @module     mod_attendance/sessions
 * @package    mod_attendance
 * @copyright  2026 Ariise LMS & ERP Solutions
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    return {
        init: function() {
            var cardSelector = '.attendance-session-card';
            var dropdownSelector = cardSelector + ' .dropdown';

            $(document).off('show.bs.dropdown show.dropdown', dropdownSelector).on(
                'show.bs.dropdown show.dropdown',
                dropdownSelector,
                function() {
                    var current = this;
                    $('.attendance-session-tiles .dropdown').not(current).each(function() {
                        var $dropdown = $(this);
                        $dropdown.removeClass('show open');
                        $dropdown.find('.dropdown-menu').removeClass('show');
                        $dropdown.find('.session-kebab-btn')
                            .attr('aria-expanded', 'false')
                            .removeClass('show');
                        $dropdown.closest(cardSelector).removeClass('has-open-dropdown').css('z-index', '');
                    });
                    $(this).closest(cardSelector).addClass('has-open-dropdown').css('z-index', '1050');
                }
            );

            $(document).off('hidden.bs.dropdown hide.dropdown', dropdownSelector).on(
                'hidden.bs.dropdown hide.dropdown',
                dropdownSelector,
                function() {
                    $(this).closest(cardSelector).removeClass('has-open-dropdown').css('z-index', '');
                }
            );

            $(document).off('click', cardSelector).on('click', cardSelector, function(event) {
                var interactiveSelector = '.dropdown, .dropdown-menu, .session-kebab-btn, ' +
                    '.session-tile-checkbox, input, a, button';
                if ($(event.target).closest(interactiveSelector).length > 0) {
                    return;
                }
                var primaryUrl = $(this).attr('data-primary-url');
                if (primaryUrl) {
                    window.location.assign(primaryUrl);
                }
            });

            $(document).off('contextmenu', cardSelector).on('contextmenu', cardSelector, function(event) {
                if ($(event.target).closest('.session-tile-checkbox, input').length > 0) {
                    return;
                }
                var $kebab = $(this).find('.session-kebab-btn');
                if ($kebab.length > 0) {
                    event.preventDefault();
                    $kebab.trigger('click');
                }
            });
        }
    };
});
