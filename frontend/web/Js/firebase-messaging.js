// frontend/web/js/firebase-messaging.js
let firebaseApp = null;
let messaging = null;
// Request CSRF token
async function getCsrfToken() {
    const res = await fetch('/fcm/csrf-token');
    const data = await res.json();
    return data.token;
}

// Initialize Firebase with config from server
async function initializeFirebase() {
    try {
        // Fetch config from your server
        const configResponse = await fetch('fcm/get-config', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const configData = await configResponse.json();

        if (!configData.success) {
            throw new Error('Failed to fetch Firebase config');
        }

        // Initialize Firebase with server-provided config
        firebaseApp = firebase.initializeApp(configData.config);
        messaging = firebase.messaging(firebaseApp);

        console.log('Firebase initialized successfully');

        // Now request notification permission
        await requestNotificationPermission();

    } catch (error) {
        console.error('Error initializing Firebase:', error);
    }
}


// Request permission and get token
async function requestNotificationPermission() {
    try {
        const permission = await Notification.requestPermission();

        if (permission === 'granted') {
            console.log('Notification permission granted.');

            // Fetch VAPID key from server
            const vapidResponse = await fetch('/fcm/get-vapid-key', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            });

            const vapidData = await vapidResponse.json();

            if (!vapidData.success) {
                throw new Error('Failed to fetch VAPID key');
            }

            // Get registration token
            const currentToken = await messaging.getToken({
                vapidKey: vapidData.vapidKey
            });

            if (currentToken) {
                console.log('Token:', currentToken);
                await sendTokenToServer(currentToken);
            } else {
                console.log('No registration token available.');
            }
        } else {
            console.log('Notification permission denied.');
        }
    } catch (err) {
        console.log('Error getting permission/token:', err);
    }
}

// Send token to Yii2 backend
async function sendTokenToServer(token) {
    try {
        token = await getCsrfToken();
        const response = await fetch('/device/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token // Yii2 CSRF token
            },
            body: JSON.stringify({
                device_token: token,
                device_type: 'web',
                user_agent: navigator.userAgent
            })
        });

        const data = await response.json();

        if (data.success) {
            console.log('Token registered successfully');
            // Store token in localStorage to avoid re-registering
            localStorage.setItem('fcm_token', token);
        }
    } catch (error) {
        console.error('Error sending token to server:', error);
    }
}

// Handle token refresh
if (messaging) {
    messaging.onTokenRefresh(async () => {
        try {
            const vapidResponse = await fetch('/fcm/get-vapid-key', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                }
            });

            const vapidData = await vapidResponse.json();

            const refreshedToken = await messaging.getToken({
                vapidKey: vapidData.vapidKey
            });

            console.log('Token refreshed:', refreshedToken);

            const oldToken = localStorage.getItem('fcm_token');
            await updateTokenOnServer(oldToken, refreshedToken);
            localStorage.setItem('fcm_token', refreshedToken);

        } catch (err) {
            console.log('Unable to retrieve refreshed token:', err);
        }
    });
}

// Initialize on page load
initializeFirebase();
