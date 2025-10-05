// frontend/web/firebase-messaging-sw.js

importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey: "AIzaSyB9RLGWN6ktOjUC8SGSk4C4WoqHn3JmZXY",
    authDomain: "healthfirst-f6a40.firebaseapp.com",
    projectId: "healthfirst-f6a40",
    storageBucket: "healthfirst-f6a40.firebasestorage.app",
    messagingSenderId: "707544416635",
    appId: "1:707544416635:web:030458beb19704b783bdde",
    measurementId: "G-T44FRDXTJM"
});

const messaging = firebase.messaging();

// Handle background messages
messaging.onBackgroundMessage((payload) => {
    console.log('Received background message:', payload);

    const notificationTitle = payload.notification.title;
    const notificationOptions = {
        body: payload.notification.body,
        icon: '/images/notification-icon.png'
    };

    self.registration.showNotification(notificationTitle, notificationOptions);
});