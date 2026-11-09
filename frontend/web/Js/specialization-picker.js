/**
 * Specialization picker: a main-area select and a sub-specialty select (single or multiple),
 * both select2 with free entry. Filtering happens in the browser on every keystroke, so
 * suggestions appear instantly with no server round-trip.
 *
 * Main-area options carry  data-desc (description) and data-children ("A|B|C" sub-specialty names).
 * Sub-specialty options carry data-desc and data-parent (main-area name).
 *
 * Ranking: name starts with the term > a word in the name starts with it > the name contains it
 *          > one of the area's sub-specialties matches > the description matches.
 */
(function ($, window) {
    'use strict';

    function normalize(text) {
        return String(text || '')
            .toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();
    }

    // Every word of the term must be the start of some word in the text
    function wordsMatch(termWords, text) {
        var words = normalize(text).split(' ');
        return termWords.every(function (tw) {
            return words.some(function (w) { return w.indexOf(tw) === 0; });
        });
    }

    function scoreName(term, termWords, name) {
        var n = normalize(name);
        if (n.indexOf(term) === 0) { return 0; }
        if (wordsMatch(termWords, name)) { return 1; }
        if (n.indexOf(term) !== -1) { return 2; }
        return null;
    }

    function highlight(text, rawTerm) {
        var $out = $('<span>');
        var term = String(rawTerm || '').trim();
        var idx = term ? text.toLowerCase().indexOf(term.toLowerCase()) : -1;
        if (idx === -1) {
            return $out.text(text);
        }
        return $out
            .append(document.createTextNode(text.slice(0, idx)))
            .append($('<mark>').text(text.slice(idx, idx + term.length)))
            .append(document.createTextNode(text.slice(idx + term.length)));
    }

    function truncate(text, max) {
        text = String(text || '');
        return text.length > max ? text.slice(0, max - 1) + '…' : text;
    }

    function optionData(data) {
        var el = data.element;
        return {
            desc: el ? (el.getAttribute('data-desc') || '') : '',
            parent: el ? (el.getAttribute('data-parent') || '') : '',
            children: el && el.getAttribute('data-children') ? el.getAttribute('data-children').split('|') : []
        };
    }

    function init(config) {
        var $main = $(config.main);
        var $sub = $(config.sub);
        if (!$main.length || typeof $.fn.select2 !== 'function') {
            return;
        }

        var terms = { main: '', sub: '' };

        // Main areas known to the catalogue, lower-cased, to tell catalogue picks from free entries
        var knownMains = {};
        $main.find('option').each(function () {
            if (this.value && this.hasAttribute('data-main')) {
                knownMains[this.value.toLowerCase()] = this.value;
            }
        });

        function currentMain() {
            var v = $main.val();
            return v ? String(v) : '';
        }

        // Sub-specialty fits the chosen main area (anything goes when no catalogue area is chosen)
        function fitsMain(parent) {
            var main = currentMain().toLowerCase();
            if (!main || !knownMains[main] || !parent) {
                return true;
            }
            return parent.toLowerCase() === main;
        }

        function makeMatcher(kind) {
            return function (params, data) {
                var info = optionData(data);
                if (kind === 'sub' && data.element && !fitsMain(info.parent)) {
                    return null;
                }

                var raw = $.trim(params.term || '');
                terms[kind] = raw;
                if (raw === '') {
                    return data;
                }

                var term = normalize(raw);
                var termWords = term.split(' ').filter(Boolean);
                var score = scoreName(term, termWords, data.text);
                var hint = '';

                if (score === null && info.children.length) {
                    for (var i = 0; i < info.children.length; i++) {
                        if (scoreName(term, termWords, info.children[i]) !== null) {
                            score = 3;
                            hint = 'includes ' + info.children[i];
                            break;
                        }
                    }
                }
                if (score === null && info.desc && wordsMatch(termWords, info.desc)) {
                    score = 4;
                }
                if (score === null) {
                    return null;
                }
                return $.extend({}, data, { _score: score, _hint: hint });
            };
        }

        function sorter(results) {
            if (!results.length || results[0]._score === undefined) {
                return results;
            }
            return results.slice().sort(function (a, b) {
                if (a.newTag || b.newTag) { return a.newTag ? 1 : -1; }
                return (a._score - b._score) || a.text.localeCompare(b.text);
            });
        }

        function makeTemplate(kind) {
            return function (data) {
                if (data.loading || !data.id) {
                    return data.text;
                }
                if (data.newTag) {
                    return $('<span class="sp-option">').append('Use "', $('<strong>').text(data.text), '"');
                }
                var info = optionData(data);
                var $row = $('<span class="sp-option">').append(highlight(data.text, terms[kind]));
                var hint = data._hint
                    || (kind === 'sub' && info.parent && !knownMains[currentMain().toLowerCase()] ? info.parent : '')
                    || truncate(info.desc, 90);
                if (hint) {
                    $row.append($('<small class="sp-option-hint text-muted">').text(hint));
                }
                return $row;
            };
        }

        // Free entry, offered after the suggestions unless the exact name already exists
        function createTag($select) {
            return function (params) {
                var term = $.trim(params.term || '');
                if (term === '') {
                    return null;
                }
                var exists = $select.find('option').filter(function () {
                    return this.value.toLowerCase() === term.toLowerCase();
                }).length;
                return exists ? null : { id: term, text: term, newTag: true };
            };
        }

        function setup($select, kind, placeholder) {
            $select.select2({
                width: '100%',
                tags: true,
                allowClear: !$select.prop('multiple'),
                placeholder: placeholder,
                matcher: makeMatcher(kind),
                sorter: sorter,
                templateResult: makeTemplate(kind),
                createTag: createTag($select),
                insertTag: function (data, tag) { data.push(tag); },
                language: { noResults: function () { return 'No matches. Keep typing to add your own.'; } }
            });
        }

        setup($main, 'main', config.mainPlaceholder || 'Search areas of specialization…');
        if ($sub.length) {
            setup($sub, 'sub', config.subPlaceholder || 'Search sub-specialities…');
        }

        // Changing the main area drops catalogue sub-specialties that belong to another area
        $main.on('change', function () {
            if (!$sub.length) { return; }
            var keep = [];
            var values = [].concat($sub.val() || []);
            values.forEach(function (value) {
                var opt = $sub.find('option').filter(function () { return this.value === value; })[0];
                var parent = opt ? (opt.getAttribute('data-parent') || '') : '';
                if (fitsMain(parent)) { keep.push(value); }
            });
            if (keep.length !== values.length) {
                $sub.val($sub.prop('multiple') ? keep : (keep[0] || null)).trigger('change');
            }
        });

        // Picking a sub-specialty first fills in its main area
        $sub.on('select2:select', function (e) {
            var parent = e.params.data.element ? e.params.data.element.getAttribute('data-parent') : '';
            if (parent && !currentMain() && $main.find('option').filter(function () { return this.value === parent; }).length) {
                $main.val(parent).trigger('change');
            }
        });
    }

    // select2 4.1 + jQuery 3.6 no longer focuses the search box on open; do it so typing starts immediately
    $(document).on('select2:open', function () {
        var field = document.querySelector('.select2-container--open .select2-search__field');
        if (field) { field.focus(); }
    });

    window.SpecializationPicker = { init: init };
})(jQuery, window);
