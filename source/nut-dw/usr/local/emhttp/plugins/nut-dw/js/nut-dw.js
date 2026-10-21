/* nut-dw shared helpers: poll scheduling that pauses in hidden tabs, and the dashboard tile renderer. */
(function () {
    if (window.nutSchedule) return; // loaded more than once on the same page
    window.timers = window.timers || {};
    var skipped = {}, pollers = {};

    /* (re)arm the next run of poller `name`; ms of 0 means auto-refresh is off */
    window.nutSchedule = function (name, fn, ms) {
        pollers[name] = fn;
        clearTimeout(window.timers[name]);
        if (ms) window.timers[name] = setTimeout(fn, ms);
    };

    /* call first in a poll function: while the tab is hidden no request is made, the timer chain
     * stays alive, and the poll runs again as soon as the tab becomes visible. Returns true if skipped. */
    window.nutSkipIfHidden = function (name, fn, ms) {
        if (!document.hidden || !ms) return false;
        skipped[name] = true;
        window.nutSchedule(name, fn, ms);
        return true;
    };

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) return;
        Object.keys(skipped).forEach(function (n) { delete skipped[n]; pollers[n](); });
    });

    function setIcon($i, cls, title) {
        $i.attr({ 'class': 'nutdsicon fa ' + cls, 'title': title, 'aria-label': title, 'role': 'img' });
    }

    /* fill the dashboard tile under `root` ("#tblUPSNUTDash" or the legacy "#db-box1") from nut_status.php?all=false */
    window.nutRenderDash = function (root, data) {
        var $r = $(root);
        if (data && data.success && data.success.response) {
            var d = data.success.response.replace(/<\/?tr>/g, '').replace(/<td/g, '<span').replace(/td>/g, 'span>').split('</span>');
            $r.find('.nut_status').html(d[0]);
            $r.find('.nut_bcharge').html(d[1]);
            $r.find('.nut_timeleft').html(d[2]);
            $r.find('.nut_nompower').html(d[3]);
            $r.find('.nut_loadpct').html(d[5] + ' - ' + d[4]);

            var st = $r.find('.nut_status').text(), $icon = $('.nutdsicon');
            if (d[0].includes('red-text')) setIcon($icon, 'fa-exclamation-circle red-text', st);
            else if (d[0].includes('orange-text')) setIcon($icon, 'fa-exclamation-circle orange-text', st);
            else if (d[0].includes('green-text')) setIcon($icon, 'fa-check-circle green-text', st);
            else setIcon($icon, 'fa-question-circle black-text', st || 'Unknown Status');

            var bc = $r.find('.nut_bcharge').text(), cc = d[1].includes('red-text') ? 'red-text' : d[1].includes('orange-text') ? 'orange-text' : d[1].includes('green-text') ? 'green-text' : 'black-text';
            $('.nutdcc').attr('class', 'nutdcc ' + cc).text(bc || '-');

            if (data.success.alarms) {
                $('.nutalarms').html("<i class='red-text fa fa-bell faa-ring animated'></i><span class='nut-sr'>UPS alarm active</span> ")
                    .attr('title', '<b>NUT Active UPS Alarm(s):</b><br>- ' + data.success.alarms.join('<br>- ')).show();
            } else {
                $('.nutalarms').html('').attr('title', '').hide();
            }
            if (typeof $.fn.tooltipster === 'function') {
                $('.nutdsicon').tooltipster();
                $('.nutalarms').tooltipster({ contentAsHTML: true });
            }
        } else {
            var err = "<i class='red-text fa fa-times-circle'></i> <em>Error (check logs)</em>";
            setIcon($('.nutdsicon'), 'fa-times-circle red-text', 'Error (check logs)');
            $('.nutdcc').html("<i class='red-text fa fa-times-circle'></i>");
            $r.find('.nut_status, .nut_bcharge, .nut_timeleft, .nut_nompower').html(err);
            $r.find('.nut_loadpct').html("<i class='red-text fa fa-times-circle'></i>");
        }
    };
})();
