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
