<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';

$isLoggedIn = isset($_SESSION['user']);
$userName = $isLoggedIn ? $_SESSION['user']['first_name'] . ' ' . $_SESSION['user']['last_name'] : '';
$userEmail = $isLoggedIn ? $_SESSION['user']['email'] : '';
$currentUser = $isLoggedIn ? $_SESSION['user']['username'] : 'guest';
$userId = $isLoggedIn ? $_SESSION['user']['id'] : 'null';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Chat Support</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .chat-widget {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 380px;
            max-height: 600px;
            z-index: 1050;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        
        .chat-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            z-index: 1051;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
            transition: all 0.3s ease;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4), 0 0 0 0 rgba(37, 99, 235, 0.4); }
            50% { box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4), 0 0 0 8px rgba(37, 99, 235, 0.1); }
            100% { box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4), 0 0 0 0 rgba(37, 99, 235, 0); }
        }
        
        .chat-toggle:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 28px rgba(37, 99, 235, 0.5);
            animation: none;
        }
        
        .chat-header {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        
        .chat-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 10px,
                rgba(255, 255, 255, 0.05) 10px,
                rgba(255, 255, 255, 0.05) 20px
            );
        }
        
        .chat-header-content {
            position: relative;
            z-index: 2;
        }
        
        .chat-header h6 {
            margin: 0 0 4px 0;
            font-size: 1.1rem;
            font-weight: 700;
        }
        
        .chat-header small {
            opacity: 0.9;
            font-size: 0.85rem;
        }
        
        .chat-body {
            height: 350px;
            overflow-y: auto;
            padding: 20px;
            background: linear-gradient(to bottom, #f8fafc 0%, #f1f5f9 100%);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .chat-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .chat-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .chat-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .chat-body::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
        
        .chat-message {
            max-width: 85%;
            padding: 12px 16px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.5;
            animation: fadeInUp 0.4s ease;
            position: relative;
            word-wrap: break-word;
        }
        
        .message-customer {
            align-self: flex-end;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: white;
            border-bottom-right-radius: 6px;
        }
        
        .message-staff {
            align-self: flex-start;
            background: white;
            color: #374151;
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .message-system {
            align-self: center;
            background: #f3f4f6;
            color: #6b7280;
            font-style: italic;
            font-size: 13px;
            border-radius: 12px;
            max-width: 90%;
            text-align: center;
        }
        
        .message-failed {
            opacity: 0.6;
            border: 1px solid #ef4444 !important;
        }
        
        .message-time {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 6px;
            text-align: right;
        }
        
        .message-staff .message-time {
            text-align: left;
        }
        
        .message-system .message-time {
            text-align: center;
            opacity: 0.6;
        }
        
        .chat-input {
            padding: 20px;
            background: white;
            border-top: 1px solid #e5e7eb;
        }
        
        .input-group {
            margin-bottom: 12px;
        }
        
        .chat-input .form-control {
            border-radius: 25px;
            border: 2px solid #e5e7eb;
            padding: 12px 18px;
            font-size: 14px;
            transition: all 0.2s ease;
        }
        
        .chat-input .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .chat-input .btn {
            border-radius: 50%;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.2s ease;
        }
        
        .chat-input .btn-primary {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            border: none;
        }
        
        .chat-input .btn-primary:hover:not(:disabled) {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .chat-input .btn-outline-secondary {
            border: 2px solid #e5e7eb;
            color: #6b7280;
        }
        
        .chat-input .btn-outline-secondary:hover:not(:disabled) {
            background: #f3f4f6;
            border-color: #d1d5db;
            transform: scale(1.05);
        }
        
        .chat-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-end-chat {
            font-size: 12px;
            padding: 6px 12px;
            border-radius: 15px;
            border: 1px solid #ef4444;
            color: #ef4444;
            background: transparent;
            transition: all 0.2s ease;
        }
        
        .btn-end-chat:hover:not(:disabled) {
            background: #ef4444;
            color: white;
        }
        
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            animation: breathe 2s infinite;
        }
        
        @keyframes breathe {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .status-online { background: #10b981; }
        .status-away { background: #f59e0b; }
        .status-offline { background: #6b7280; }
        
        .guest-form {
            padding: 25px;
            background: white;
        }
        
        .guest-form h6 {
            color: #374151;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .guest-form .form-control {
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            padding: 12px 16px;
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        
        .guest-form .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .guest-form .btn {
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            border: none;
            transition: all 0.2s ease;
        }
        
        .guest-form .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1060;
            min-width: 300px;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.3s ease;
        }
        
        .notification-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .notification-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .connection-status {
            position: absolute;
            top: 10px;
            right: 50px;
            font-size: 11px;
            opacity: 0.8;
            z-index: 3;
        }
        
        .connection-online { color: #10b981; }
        .connection-offline { color: #ef4444; }
        .connection-connecting { color: #f59e0b; }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .file-message {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            margin-top: 8px;
            font-size: 13px;
        }
        
        .message-staff .file-message {
            background: rgba(37, 99, 235, 0.1);
        }
        
        @media (max-width: 768px) {
            .chat-widget {
                width: calc(100vw - 20px);
                right: 10px;
                bottom: 10px;
                max-width: 350px;
            }
            
            .chat-toggle {
                width: 56px;
                height: 56px;
                right: 15px;
                bottom: 15px;
                font-size: 22px;
            }
            
            .chat-body {
                height: 280px;
            }
        }
    </style>
</head>
<body>

<!-- Chat Toggle Button -->
<button class="chat-toggle" id="chatToggle" onclick="toggleChat()">
    <i class="bi bi-chat-dots" id="chatIcon"></i>
</button>

<!-- Chat Widget -->
<div class="chat-widget" id="chatWidget" style="display: none;">
    <!-- Chat Header -->
    <div class="chat-header">
        <div class="chat-header-content">
            <h6>Live Support</h6>
            <small><span class="status-indicator status-online"></span>We're here to help!</small>
        </div>
        <div class="connection-status" id="connectionStatus">
            <span class="connection-online"><i class="bi bi-wifi"></i> Connected</span>
        </div>
        <button class="btn btn-sm text-white position-relative" onclick="toggleChat()" style="z-index: 3;">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    
    <!-- Guest Form (shown when not logged in) -->
    <div class="guest-form" id="guestForm" style="display: <?= $isLoggedIn ? 'none' : 'block' ?>;">
        <h6><i class="bi bi-chat-heart me-2"></i>Start a conversation</h6>
        <p class="text-muted small mb-3">We typically reply within a few minutes</p>
        
        <div class="mb-3">
            <input type="text" class="form-control" id="guestName" placeholder="Your name *" 
                   value="<?= htmlspecialchars($userName) ?>" required maxlength="100">
        </div>
        <div class="mb-3">
            <input type="email" class="form-control" id="guestEmail" placeholder="Your email *" 
                   value="<?= htmlspecialchars($userEmail) ?>" required maxlength="100">
        </div>
        <button class="btn btn-primary w-100" onclick="startChat()" id="startChatBtn">
            <i class="bi bi-chat-dots me-2"></i>Start Chat
        </button>
        
        <div class="text-center mt-3">
            <small class="text-muted">
                <i class="bi bi-shield-check me-1"></i>Your information is secure
            </small>
        </div>
    </div>
    
    <!-- Chat Interface -->
    <div id="chatInterface" style="display: <?= $isLoggedIn ? 'block' : 'none' ?>;">
        <!-- Chat Body -->
        <div class="chat-body" id="chatBody">
            <div class="chat-message message-system">
                <div><i class="bi bi-heart-fill me-2" style="color: #ef4444;"></i>Welcome! How can we help you today?</div>
                <div class="message-time" id="welcomeTime"></div>
            </div>
        </div>
        
        <!-- Chat Input -->
        <div class="chat-input">
            <div class="input-group">
                <input type="text" class="form-control" id="messageInput" 
                       placeholder="Type your message..." 
                       onkeypress="handleKeyPress(event)" 
                       disabled
                       maxlength="1000">
                <button class="btn btn-primary" onclick="sendMessage()" id="sendBtn" disabled>
                    <i class="bi bi-send"></i>
                </button>
                <button class="btn btn-outline-secondary" onclick="document.getElementById('fileInput').click()" 
                        id="fileBtn" disabled>
                    <i class="bi bi-paperclip"></i>
                </button>
            </div>
            <input type="file" id="fileInput" style="display: none;" onchange="uploadFile()" 
                   accept="image/*,.pdf,.txt,.doc,.docx,.csv">
            
            <div class="chat-actions">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>Press Enter to send • Max 1000 chars
                </small>
                <button class="btn-end-chat" onclick="endChat()" id="endChatBtn" disabled>
                    <i class="bi bi-x-circle me-1"></i>End Chat
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Chat variables
let sessionToken = null;
let lastMessageId = 0;
let pollInterval = null;
let connectionStatus = 'online';
let retryCount = 0;
let maxRetries = 3;

// API configuration with fallbacks
const API_ENDPOINTS = [
    '/chat-api.php',
    '<?= $_SERVER['REQUEST_SCHEME'] ?>://<?= $_SERVER['HTTP_HOST'] ?>/chat-api.php',
    './chat-api.php'
];
let currentApiIndex = 0;

// DOM elements
const chatWidget = document.getElementById('chatWidget');
const chatToggle = document.getElementById('chatToggle');
const chatIcon = document.getElementById('chatIcon');
const chatBody = document.getElementById('chatBody');
const messageInput = document.getElementById('messageInput');
const sendBtn = document.getElementById('sendBtn');
const fileBtn = document.getElementById('fileBtn');
const endChatBtn = document.getElementById('endChatBtn');
const guestForm = document.getElementById('guestForm');
const chatInterface = document.getElementById('chatInterface');
const connectionStatusEl = document.getElementById('connectionStatus');
const startChatBtn = document.getElementById('startChatBtn');

// User info from PHP
const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
const currentUserId = <?= $userId ?>;

// Initialize welcome time
document.addEventListener('DOMContentLoaded', function() {
    const welcomeTimeEl = document.getElementById('welcomeTime');
    if (welcomeTimeEl) {
        welcomeTimeEl.textContent = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }
    
    console.log('Chat widget loaded for user: <?= $currentUser ?>');
    console.log('User logged in:', isLoggedIn);
    console.log('User ID:', currentUserId);
    
    // Test API connection on load
    testApiConnection();
});

// Test API endpoints to find working one
async function testApiConnection() {
    for (let i = 0; i < API_ENDPOINTS.length; i++) {
        try {
            console.log('Testing API endpoint:', API_ENDPOINTS[i]);
            const response = await fetch(API_ENDPOINTS[i] + '?action=test');
            
            if (response.ok) {
                const result = await response.json();
                if (result.success) {
                    console.log('Working API endpoint found:', API_ENDPOINTS[i]);
                    currentApiIndex = i;
                    updateConnectionStatus('online');
                    return true;
                }
            }
        } catch (error) {
            console.log('API endpoint failed:', API_ENDPOINTS[i], error.message);
        }
    }
    
    console.error('No working API endpoint found');
    updateConnectionStatus('offline');
    showNotification('Chat service unavailable. Please refresh the page.', 'error');
    return false;
}

// Get current API endpoint
function getApiEndpoint() {
    return API_ENDPOINTS[currentApiIndex];
}

function toggleChat() {
    if (chatWidget.style.display === 'none') {
        chatWidget.style.display = 'block';
        chatToggle.style.display = 'none';
        
        // Auto start chat for logged in users
        if (isLoggedIn && !sessionToken) {
            startChat();
        }
    } else {
        chatWidget.style.display = 'none';
        chatToggle.style.display = 'flex';
    }
}

async function startChat() {
    // Test API first
    if (!(await testApiConnection())) {
        showNotification('Chat service unavailable', 'error');
        resetStartButton();
        return;
    }
    
    // Disable button to prevent double submission
    if (startChatBtn) {
        startChatBtn.disabled = true;
        startChatBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Starting...';
    }
    
    let formData = new FormData();
    formData.append('action', 'start_session');
    
    if (!isLoggedIn) {
        const name = document.getElementById('guestName').value.trim();
        const email = document.getElementById('guestEmail').value.trim();
        
        if (!name || !email) {
            showNotification('Please enter your name and email', 'error');
            resetStartButton();
            return;
        }
        
        if (!isValidEmail(email)) {
            showNotification('Please enter a valid email address', 'error');
            resetStartButton();
            return;
        }
        
        formData.append('name', name);
        formData.append('email', email);
    }
    
    try {
        updateConnectionStatus('connecting');
        
        const response = await fetch(getApiEndpoint(), {
            method: 'POST',
            body: formData
        });
        
        console.log('Start chat response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const responseText = await response.text();
        console.log('Start chat raw response:', responseText);
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            console.error('Response text:', responseText);
            showNotification('Server error. Please try again.', 'error');
            updateConnectionStatus('offline');
            resetStartButton();
            return;
        }
        
        if (result.success) {
            sessionToken = result.session_token;
            lastMessageId = 0; // Reset message ID for new session
            
            console.log('Chat started successfully with token:', sessionToken.substring(0, 8) + '...');
            
            // Hide guest form, show chat interface
            guestForm.style.display = 'none';
            chatInterface.style.display = 'block';
            
            // Enable inputs
            enableChatInputs();
            
            // Start polling for messages
            startPolling();
            
            // Focus message input
            messageInput.focus();
            
            updateConnectionStatus('online');
            
        } else {
            console.error('Start chat error:', result.error);
            showNotification('Failed to start chat: ' + (result.error || 'Unknown error'), 'error');
            updateConnectionStatus('offline');
            resetStartButton();
        }
    } catch (error) {
        console.error('Start chat network error:', error);
        showNotification('Connection error. Please check your internet connection.', 'error');
        updateConnectionStatus('offline');
        resetStartButton();
    }
}

function resetStartButton() {
    if (startChatBtn) {
        startChatBtn.disabled = false;
        startChatBtn.innerHTML = '<i class="bi bi-chat-dots me-2"></i>Start Chat';
    }
}

async function sendMessage() {
    const message = messageInput.value.trim();
    if (!message || !sessionToken) return;
    
    // Clear input immediately
    messageInput.value = '';
    
    // Add message to chat immediately (optimistic update)
    const messageId = addMessage('customer', message, 'You', new Date());
    
    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('session_token', sessionToken);
    formData.append('message', message);
    
    try {
        const response = await fetch(getApiEndpoint(), {
            method: 'POST',
            body: formData
        });
        
        console.log('Send message response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const responseText = await response.text();
        console.log('Send message response:', responseText);
        
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (parseError) {
            console.error('JSON parse error:', parseError);
            showNotification('Server error. Please try again.', 'error');
            markMessageAsFailed(messageId);
            return;
        }
        
        if (result.success) {
            console.log('Message sent successfully');
            updateConnectionStatus('online');
            retryCount = 0;
        } else {
            console.error('Send message error:', result.error);
            showNotification('Failed to send: ' + result.error, 'error');
            markMessageAsFailed(messageId);
        }
    } catch (error) {
        console.error('Send message network error:', error);
        showNotification('Connection error. Message not sent.', 'error');
        markMessageAsFailed(messageId);
        updateConnectionStatus('offline');
    }
}

function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    
    pollInterval = setInterval(async () => {
        if (!sessionToken) return;
        
        try {
            const url = `${getApiEndpoint()}?action=get_messages&session_token=${encodeURIComponent(sessionToken)}&last_message_id=${lastMessageId}`;
            const response = await fetch(url);
            
            console.log('Polling response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const responseText = await response.text();
            
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Polling JSON parse error:', parseError);
                updateConnectionStatus('offline');
                retryCount++;
                return;
            }
            
            if (result.success) {
                if (result.messages && result.messages.length > 0) {
                    result.messages.forEach(message => {
                        const messageId = parseInt(message.id);
                        
                        // Only add new messages we haven't seen
                        if (messageId > lastMessageId) {
                            // Skip our own customer messages (already shown optimistically)
                            const isOwnMessage = message.sender_type === 'customer' && 
                                                currentUserId !== null && 
                                                parseInt(message.sender_id) === currentUserId;
                            
                            if (!isOwnMessage) {
                                addMessage(message.sender_type, message.message, message.sender_name, message.created_at, message.message_type);
                            }
                            
                            lastMessageId = Math.max(lastMessageId, messageId);
                        }
                    });
                }
                updateConnectionStatus('online');
                retryCount = 0;
            } else {
                console.error('Polling error:', result.error);
                retryCount++;
            }
        } catch (error) {
            console.error('Polling network error:', error);
            updateConnectionStatus('offline');
            retryCount++;
        }
        
        // Stop polling if too many failures
        if (retryCount >= maxRetries) {
            console.log('Max polling retries reached, stopping');
            stopPolling();
            showNotification('Connection lost. Please refresh the page.', 'error');
        }
    }, 3000); // Poll every 3 seconds
}

function stopPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

async function endChat() {
    if (!sessionToken) return;
    
    if (!confirm('Are you sure you want to end this chat?')) return;
    
    const formData = new FormData();
    formData.append('action', 'end_session');
    formData.append('session_token', sessionToken);
    
    try {
        const response = await fetch(getApiEndpoint(), {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            addSystemMessage('💙 Chat ended. Thank you for contacting us!');
            stopPolling();
            disableChatInputs();
            
            setTimeout(() => {
                resetChat();
            }, 3000);
        } else {
            showNotification('Error ending chat: ' + result.error, 'error');
        }
    } catch (error) {
        console.error('End chat error:', error);
        showNotification('Error ending chat.', 'error');
    }
}

async function uploadFile() {
    const fileInput = document.getElementById('fileInput');
    const file = fileInput.files[0];
    
    if (!file || !sessionToken) return;
    
    // Check file size (5MB limit)
    if (file.size > 5 * 1024 * 1024) {
        showNotification('File too large. Maximum size is 5MB.', 'error');
        fileInput.value = '';
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'upload_file');
    formData.append('session_token', sessionToken);
    formData.append('file', file);
    
    const progressId = addSystemMessage('📤 Uploading file...');
    
    try {
        const response = await fetch(getApiEndpoint(), {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        removeMessage(progressId);
        
        if (result.success) {
            addSystemMessage('✅ File uploaded: ' + result.file_name);
        } else {
            showNotification('File upload failed: ' + result.error, 'error');
        }
    } catch (error) {
        console.error('Upload error:', error);
        removeMessage(progressId);
        showNotification('File upload error. Please try again.', 'error');
    }
    
    fileInput.value = '';
}

// Helper functions
function addMessage(senderType, message, senderName, timestamp, messageType = 'text') {
    const messageId = 'msg_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    const messageDiv = document.createElement('div');
    messageDiv.className = `chat-message message-${senderType}`;
    messageDiv.setAttribute('data-message-id', messageId);
    
    let content = `<div>${escapeHtml(message)}</div>`;
    
    if (messageType === 'file') {
        content += `<div class="file-message">
            <i class="bi bi-file-earmark"></i>
            <span>File attachment</span>
        </div>`;
    }
    
    const time = timestamp instanceof Date ? 
        timestamp.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) :
        new Date(timestamp).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    content += `<div class="message-time">${time}</div>`;
    
    messageDiv.innerHTML = content;
    chatBody.appendChild(messageDiv);
    chatBody.scrollTop = chatBody.scrollHeight;
    
    return messageId;
}

function addSystemMessage(message) {
    return addMessage('system', message, 'System', new Date());
}

function markMessageAsFailed(messageId) {
    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (messageEl) {
        messageEl.classList.add('message-failed');
        const timeEl = messageEl.querySelector('.message-time');
        if (timeEl) {
            timeEl.innerHTML += ' <i class="bi bi-exclamation-triangle text-danger" title="Failed to send"></i>';
        }
    }
}

function removeMessage(messageId) {
    const messageEl = document.querySelector(`[data-message-id="${messageId}"]`);
    if (messageEl) {
        messageEl.remove();
    }
}

function showNotification(message, type) {
    // Prevent spam by checking for existing identical notifications
    const existing = Array.from(document.querySelectorAll('.notification')).find(n => 
        n.textContent.includes(message) && n.classList.contains(`notification-${type}`)
    );
    
    if (existing) return;
    
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    
    const icon = type === 'error' ? 'bi-exclamation-triangle' : 'bi-check-circle';
    notification.innerHTML = `<i class="bi ${icon}"></i>${message}`;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 4000);
}

function updateConnectionStatus(status) {
    connectionStatus = status;
    const statusEl = connectionStatusEl;
    
    switch(status) {
        case 'online':
            statusEl.innerHTML = '<span class="connection-online"><i class="bi bi-wifi"></i> Connected</span>';
            break;
        case 'offline':
            statusEl.innerHTML = '<span class="connection-offline"><i class="bi bi-wifi-off"></i> Offline</span>';
            break;
        case 'connecting':
            statusEl.innerHTML = '<span class="connection-connecting"><i class="bi bi-hourglass-split"></i> Connecting...</span>';
            break;
    }
}

function enableChatInputs() {
    messageInput.disabled = false;
    sendBtn.disabled = false;
    fileBtn.disabled = false;
    endChatBtn.disabled = false;
}

function disableChatInputs() {
    messageInput.disabled = true;
    sendBtn.disabled = true;
    fileBtn.disabled = true;
    endChatBtn.disabled = true;
}

function handleKeyPress(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

function resetChat() {
    sessionToken = null;
    lastMessageId = 0;
    retryCount = 0;
    
    chatBody.innerHTML = `
        <div class="chat-message message-system">
            <div><i class="bi bi-heart-fill me-2" style="color: #ef4444;"></i>Welcome! How can we help you today?</div>
            <div class="message-time">${new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
        </div>
    `;
    messageInput.value = '';
    
    if (!isLoggedIn) {
        guestForm.style.display = 'block';
        chatInterface.style.display = 'none';
        resetStartButton();
    } else {
        enableChatInputs();
    }
    
    updateConnectionStatus('online');
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#chat') {
        toggleChat();
    }
});

window.addEventListener('beforeunload', function() {
    stopPolling();
});

window.addEventListener('online', function() {
    testApiConnection();
    if (sessionToken && !pollInterval) {
        startPolling();
    }
});

window.addEventListener('offline', function() {
    updateConnectionStatus('offline');
    stopPolling();
});
</script>

</body>
</html>