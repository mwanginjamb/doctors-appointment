function fetchCalendarEvents(fetchInfo, successCallback, failureCallback) {
    let modifiedStart = new Date(fetchInfo.start);
    let modifiedEnd = new Date(fetchInfo.end);

    // Adjust range (fetch a week's worth of data)
    modifiedStart.setDate(modifiedStart.getDate() - 3); // Start 3 days earlier
    modifiedEnd.setDate(modifiedEnd.getDate() + 3); // End 3 days later

    let formattedStart = modifiedStart.toISOString();
    let formattedEnd = modifiedEnd.toISOString();

    // Construct the API URL
    let apiUrl = `/api/appointments?start=${encodeURIComponent(formattedStart)}&end=${encodeURIComponent(formattedEnd)}`;

    fetch(apiUrl)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => successCallback(data)) // Send data to FullCalendar
        .catch(error => {
            console.error('Error fetching events:', error);
            failureCallback(error);
        });

}

/**
 * FullCalendar eventDidMount: a hover tooltip with the event's description (else its reason or
 * title). Background blocks (facility hours, sessions) can have none: Bootstrap 5 throws on an
 * undefined title, and an error here aborts FullCalendar's render, so the arrows and view buttons
 * stop working. No text, no tooltip; and a failing tooltip never breaks the calendar.
 */
function addEventTooltip(info) {
    var props = info.event.extendedProps || {};
    var text = props.description || props.reason || info.event.title;
    if (typeof text !== 'string' || text.trim() === '' || typeof $ === 'undefined' || !$.fn.tooltip) {
        return;
    }
    try {
        $(info.el).tooltip({ title: text, placement: 'top', trigger: 'hover', container: 'body' });
    } catch (e) {
        console.warn('Tooltip skipped:', e);
    }
}
