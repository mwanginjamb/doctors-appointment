// frontend/web/js/firebase-messaging.js

// Initialize Firebase
const firebaseConfig = {
    apiKey: "AIzaSyB9RLGWN6ktOjUC8SGSk4C4WoqHn3JmZXY",
    authDomain: "healthfirst-f6a40.firebaseapp.com",
    projectId: "healthfirst-f6a40",
    storageBucket: "healthfirst-f6a40.firebasestorage.app",
    messagingSenderId: "707544416635",
    appId: "1:707544416635:web:030458beb19704b783bdde",
    measurementId: "G-T44FRDXTJM"
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

// Request permission and get token
async function requestNotificationPermission() {
    try {
        // Request permission from user
        const permission = await Notification.requestPermission();

        if (permission === 'granted') {
            console.log('Notification permission granted.');

            // Get registration token
            const currentToken = await messaging.getToken({
                vapidKey: 'BLMtFs3UYsu43l0RS88q1nnjESieQPju4YOJAXY7FdpvUitl2uxnsR5dzXrEbOC-9Vrt55W0d6vpupQ1raX9oX8' // Get from Firebase Console
            });

            if (currentToken) {
                console.log('Token:', currentToken);

                // Send token to your Yii2 backend
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
        const response = await fetch('/device/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': yii.getCsrfToken() // Yii2 CSRF token
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

// Call this when user logs in or on page load
requestNotificationPermission();