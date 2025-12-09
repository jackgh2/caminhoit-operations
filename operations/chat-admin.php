<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ChatManager.php';

// Access control (Staff and Admin only)
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['administrator', 'staff'])) {
    header('Location: /login.php');
    exit;
}

$chatManager = new ChatManager($pdo);
$activeSessions = $chatManager->getActiveSessions();
$templates = $chatManager->getTemplates();

$page_title = "Live Chat Management | CaminhoIT";
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header-v2-auth.php'; ?>


<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --danger-gradient: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        --border-radius: 12px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
        background: #F8FAFC;
    }

    .container {
        max-width: 1400px;
    }

    .card, .box, .panel {
        background: white;
        border-radius: var(--border-radius);
        padding: 1.5rem;
        box-shadow: var(--card-shadow);
        margin-bottom: 1.5rem;
    }

    .btn-primary {
        background: var(--primary-gradient);
        border: none;
        color: white;
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        transition: var(--transition);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
    }

    table.table {
        background: white;
        border-radius: var(--border-radius);
        overflow: hidden;
    }

    .table thead {
        background: #F8FAFC;
    }

    .badge {
        padding: 0.375rem 0.75rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .modal {
        z-index: 1050;
    }

    .modal-content {
        border-radius: var(--border-radius);
    }
</style>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/nav-auth.php'; ?>

<div class="main-container">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h1 class="sidebar-title">
                <i class="bi bi-chat-dots-fill"></i>
                Live Chat Admin
            </h1>
            <p class="sidebar-subtitle">Manage customer conversations and support requests</p>
            
            <div class="stats-row">
                <div class="stat-item">
                    <span class="stat-number" id="activeCount"><?= count($activeSessions) ?></span>
                    <div class="stat-label">Active Chats</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number" id="unreadCount">0</span>
                    <div class="stat-label">Unread</div>
                </div>
                <button class="refresh-btn" onclick="refreshSessions()" title="Refresh Sessions">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
        </div>

        <div class="sessions-list" id="sessionsList">
            <?php if (empty($activeSessions)): ?>
                <div class="empty-state">
                    <i class="bi bi-chat-dots empty-icon"></i>
                    <h6 class="empty-title">No Active Chats</h6>
                    <p class="empty-text">New conversations will appear here when customers start chatting</p>
                </div>
            <?php else: ?>
                <?php foreach ($activeSessions as $session): ?>
                    <div class="session-item <?= $session['unread_count'] > 0 ? 'unread' : '' ?>" 
                         data-session-id="<?= $session['id'] ?>" 
                         data-session-token="<?= htmlspecialchars($session['session_token']) ?>" 
                         onclick="openChat(<?= $session['id'] ?>, '<?= htmlspecialchars($session['session_token']) ?>')">
                        
                        <div class="session-header">
                            <div class="customer-info">
                                <div class="customer-name"><?= htmlspecialchars($session['customer_name']) ?></div>
                                <div class="customer-email"><?= htmlspecialchars($session['customer_email']) ?></div>
                            </div>
                            <div class="session-badges">
                                <?php if ($session['unread_count'] > 0): ?>
                                    <div class="unread-badge"><?= $session['unread_count'] ?></div>
                                <?php endif; ?>
                                <div class="status-dot status-<?= $session['status'] ?>"></div>
                            </div>
                        </div>
                        
                        <?php if ($session['last_message']): ?>
                            <div class="last-message">
                                <?= htmlspecialchars($session['last_message']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="session-footer">
                            <div class="session-time">
                                <?php if ($session['last_message_time']): ?>
                                    <?= date('H:i', strtotime($session['last_message_time'])) ?>
                                <?php else: ?>
                                    <?= date('H:i', strtotime($session['created_at'])) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($session['staff_name']): ?>
                                <div class="assigned-staff">
                                    <i class="bi bi-person-check-fill"></i>
                                    <span><?= htmlspecialchars($session['staff_name']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chat Container -->
    <div class="chat-container">
        <div id="emptyChatState" class="empty-state">
            <i class="bi bi-chat-text-fill empty-icon"></i>
            <h2 class="empty-title">Select a Conversation</h2>
            <p class="empty-text">Choose a chat from the sidebar to start helping customers and managing their requests</p>
        </div>

        <div id="chatInterface" style="display: none; height: 100%; display: flex; flex-direction: column;">
            <!-- Chat Header -->
            <div class="chat-header">
                <div class="chat-customer-info">
                    <h4 id="chatCustomerName">Customer Name</h4>
                    <small id="chatCustomerEmail">customer@email.com</small>
                </div>
                <div class="chat-actions">
                    <button class="btn btn-light" onclick="convertToTicket()" id="convertBtn">
                        <i class="bi bi-ticket-perforated-fill"></i>Create Ticket
                    </button>
                    <button class="archive-btn" onclick="archiveChat()" id="archiveBtn">
                        <i class="bi bi-archive-fill"></i>Archive
                    </button>
                    <button class="btn btn-outline-light" onclick="endCurrentChat()" id="endBtn">
                        <i class="bi bi-x-circle-fill"></i>End Chat
                    </button>
                </div>
            </div>

            <!-- Customer Details -->
            <div class="customer-details" id="customerDetails" style="display: none;">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Customer:</strong> <span id="detailCustomerName">-</span><br>
                        <strong>Email:</strong> <span id="detailCustomerEmail">-</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Session Started:</strong> <span id="detailSessionTime">-</span><br>
                        <strong>Status:</strong> <span id="detailSessionStatus">-</span>
                    </div>
                </div>
            </div>

            <!-- Messages Area -->
            <div class="messages-area" id="chatMessages">
                <!-- Messages will be loaded here -->
            </div>

            <!-- Quick Responses -->
            <div class="quick-responses">
                <div class="quick-responses-title">Quick Responses</div>
                <div class="template-buttons">
                    <?php foreach ($templates as $template): ?>
                        <button class="template-btn" onclick="useTemplate('<?= htmlspecialchars($template['content']) ?>')" title="<?= htmlspecialchars($template['title']) ?>">
                            <?= htmlspecialchars($template['title']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Input Section -->
            <div class="input-section">
                <div class="input-container">
                    <textarea class="message-input" id="messageInput" 
                            placeholder="Type your message..." 
                            rows="1" 
                            onkeydown="handleKeyPress(event)"
                            oninput="autoResize(this)"
                            maxlength="1000"></textarea>
                    <button class="send-button" onclick="sendMessage()" id="sendBtn">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
                <div class="input-help">
                    <span>
                        <i class="bi bi-info-circle me-1"></i>Press Enter to send • Shift+Enter for new line • Max 1000 chars
                    </span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="assignToMe()" id="assignBtn" style="display: none;">
                        <i class="bi bi-person-plus-fill me-1"></i>Assign to Me
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Admin chat variables
let currentSessionId = null;
let currentSessionToken = null;
let lastMessageId = 0;
let pollInterval = null;
let refreshInterval = null;
let currentUserId = <?= $_SESSION['user']['id'] ?>;
let currentUserName = '<?= $_SESSION['user']['username'] ?>';

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    console.log('Enhanced Chat Admin loaded for user:', currentUserName);
    
    // Auto-refresh sessions every 10 seconds
    refreshInterval = setInterval(refreshSessions, 10000);
    
    // Auto-select first session if available
    const firstSession = document.querySelector('.session-item');
    if (firstSession) {
        setTimeout(() => firstSession.click(), 100);
    }
    
    updateUnreadCount();
});

// Auto-resize textarea
function autoResize(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
}

// Enhanced key press handler - ENTER SENDS MESSAGE!
function handleKeyPress(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
    // Shift+Enter creates new line (default behavior)
}

function openChat(sessionId, sessionToken) {
    console.log('Opening enhanced chat session:', sessionId);
    
    currentSessionId = sessionId;
    currentSessionToken = sessionToken;
    lastMessageId = 0;
    
    // Clear polling
    if (pollInterval) clearInterval(pollInterval);
    
    // Update UI
    document.querySelectorAll('.session-item').forEach(item => {
        item.classList.remove('active');
    });
    
    const sessionItem = document.querySelector(`[data-session-id="${sessionId}"]`);
    if (sessionItem) {
        sessionItem.classList.add('active');
        sessionItem.classList.remove('unread');
        
        // Remove unread badge
        const badge = sessionItem.querySelector('.unread-badge');
        if (badge) badge.remove();
    }
    
    // Show chat interface
    document.getElementById('emptyChatState').style.display = 'none';
    document.getElementById('chatInterface').style.display = 'flex';
    document.getElementById('customerDetails').style.display = 'block';
    
    // Load data
    loadSessionDetails(sessionId, sessionToken);
    loadMessages();
    startPolling();
    
    // Focus input
    setTimeout(() => {
        document.getElementById('messageInput').focus();
    }, 100);
    
    updateUnreadCount();
}

function loadSessionDetails(sessionId, sessionToken) {
    fetch('/api/chat/session-details.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: sessionId, session_token: sessionToken })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const session = data.session;
            
            // Update header
            document.getElementById('chatCustomerName').textContent = session.customer_name;
            document.getElementById('chatCustomerEmail').textContent = session.customer_email;
            
            // Update details
            document.getElementById('detailCustomerName').textContent = session.customer_name;
            document.getElementById('detailCustomerEmail').textContent = session.customer_email;
            document.getElementById('detailSessionTime').textContent = formatDateTime(session.created_at);
            document.getElementById('detailSessionStatus').textContent = session.status.charAt(0).toUpperCase() + session.status.slice(1);
            
            // Show/hide assign button
            const assignBtn = document.getElementById('assignBtn');
            if (!session.staff_id || session.staff_id != currentUserId) {
                assignBtn.style.display = 'inline-block';
            } else {
                assignBtn.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Error loading session details:', error);
        showNotification('Failed to load session details', 'error');
    });
}

function loadMessages() {
    if (!currentSessionId) return;
    
    fetch('/api/chat/load-messages.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: currentSessionId,
            last_message_id: lastMessageId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const messagesContainer = document.getElementById('chatMessages');
            
            if (lastMessageId === 0) {
                messagesContainer.innerHTML = '';
            }
            
            data.messages.forEach(message => {
                appendMessage(message);
                lastMessageId = Math.max(lastMessageId, message.id);
            });
            
            scrollToBottom();
            
            if (data.messages.length > 0) {
                markMessagesAsRead();
            }
        }
    })
    .catch(error => {
        console.error('Error loading messages:', error);
    });
}

function appendMessage(message) {
    const messagesContainer = document.getElementById('chatMessages');
    const messageDiv = document.createElement('div');
    
    let messageClass = 'message';
    if (message.sender_type === 'staff') {
        messageClass += ' message-staff';
    } else if (message.sender_type === 'customer') {
        messageClass += ' message-customer';
    } else {
        messageClass += ' message-system';
    }
    
    messageDiv.className = messageClass;
    messageDiv.innerHTML = `
        <div class="message-bubble">
            ${escapeHtml(message.message)}
        </div>
        <div class="message-meta">
            ${formatTime(message.created_at)}
            ${message.sender_name ? '• ' + escapeHtml(message.sender_name) : ''}
        </div>
    `;
    
    messagesContainer.appendChild(messageDiv);
}

function startPolling() {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(loadMessages, 3000);
}

function sendMessage() {
    const messageInput = document.getElementById('messageInput');
    const message = messageInput.value.trim();
    
    if (!message || !currentSessionId) return;
    
    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<div class="loading-spinner"></div>';
    
    fetch('/api/chat/send-message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: currentSessionId,
            message: message,
            sender_type: 'staff'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            messageInput.value = '';
            autoResize(messageInput);
            loadMessages();
            showNotification('Message sent successfully');
        } else {
            showNotification(data.message || 'Failed to send message', 'error');
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
        showNotification('Failed to send message', 'error');
    })
    .finally(() => {
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<i class="bi bi-send-fill"></i>';
        messageInput.focus();
    });
}

function useTemplate(content) {
    const messageInput = document.getElementById('messageInput');
    messageInput.value = content;
    autoResize(messageInput);
    messageInput.focus();
}

function assignToMe() {
    if (!currentSessionId) return;
    
    fetch('/api/chat/assign-session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: currentSessionId,
            staff_id: currentUserId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('assignBtn').style.display = 'none';
            showNotification('Session assigned to you');
            
            appendMessage({
                id: 'temp-' + Date.now(),
                message: 'Chat assigned to ' + currentUserName,
                sender_type: 'system',
                created_at: new Date().toISOString(),
                sender_name: null
            });
            
            scrollToBottom();
        } else {
            showNotification(data.message || 'Failed to assign session', 'error');
        }
    })
    .catch(error => {
        console.error('Error assigning session:', error);
        showNotification('Failed to assign session', 'error');
    });
}

// NEW: Archive functionality
function archiveChat() {
    if (!currentSessionId) return;
    
    if (!confirm('Archive this chat? It will be moved to the archive and no longer appear in active chats.')) {
        return;
    }
    
    const archiveBtn = document.getElementById('archiveBtn');
    archiveBtn.disabled = true;
    const originalText = archiveBtn.innerHTML;
    archiveBtn.innerHTML = '<div class="loading-spinner me-1"></div>Archiving...';
    
    fetch('/api/chat/archive-session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: currentSessionId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Chat archived successfully');
            
            appendMessage({
                id: 'temp-' + Date.now(),
                message: 'Chat archived by ' + currentUserName,
                sender_type: 'system',
                created_at: new Date().toISOString(),
                sender_name: null
            });
            
            scrollToBottom();
            
            setTimeout(() => {
                refreshSessions();
                currentSessionId = null;
                currentSessionToken = null;
                document.getElementById('emptyChatState').style.display = 'flex';
                document.getElementById('chatInterface').style.display = 'none';
            }, 1000);
        } else {
            showNotification(data.message || 'Failed to archive chat', 'error');
        }
    })
    .catch(error => {
        console.error('Error archiving chat:', error);
        showNotification('Failed to archive chat', 'error');
    })
    .finally(() => {
        archiveBtn.disabled = false;
        archiveBtn.innerHTML = originalText;
    });
}

function convertToTicket() {
    if (!currentSessionId) return;
    
    if (!confirm('Convert this chat to a support ticket? This will end the chat session.')) {
        return;
    }
    
    const convertBtn = document.getElementById('convertBtn');
    convertBtn.disabled = true;
    const originalText = convertBtn.innerHTML;
    convertBtn.innerHTML = '<div class="loading-spinner me-1"></div>Converting...';
    
    fetch('/api/chat/convert-to-ticket.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: currentSessionId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Chat converted to ticket #' + data.ticket_id);
            
            appendMessage({
                id: 'temp-' + Date.now(),
                message: 'Chat converted to support ticket #' + data.ticket_id,
                sender_type: 'system',
                created_at: new Date().toISOString(),
                sender_name: null
            });
            
            scrollToBottom();
            
            setTimeout(() => {
                refreshSessions();
                if (confirm('View the created ticket?')) {
                    window.open('/ticket-view.php?id=' + data.ticket_id, '_blank');
                }
            }, 1000);
        } else {
            showNotification(data.message || 'Failed to convert to ticket', 'error');
        }
    })
    .catch(error => {
        console.error('Error converting to ticket:', error);
        showNotification('Failed to convert to ticket', 'error');
    })
    .finally(() => {
        convertBtn.disabled = false;
        convertBtn.innerHTML = originalText;
    });
}

function endCurrentChat() {
    if (!currentSessionId) return;
    
    if (!confirm('End this chat session? The customer will be notified.')) {
        return;
    }
    
    const endBtn = document.getElementById('endBtn');
    endBtn.disabled = true;
    const originalText = endBtn.innerHTML;
    endBtn.innerHTML = '<div class="loading-spinner me-1"></div>Ending...';
    
    fetch('/api/chat/end-session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: currentSessionId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Chat session ended');
            
            appendMessage({
                id: 'temp-' + Date.now(),
                message: 'Chat session ended by ' + currentUserName,
                sender_type: 'system',
                created_at: new Date().toISOString(),
                sender_name: null
            });
            
            scrollToBottom();
            
            if (pollInterval) clearInterval(pollInterval);
            
            setTimeout(() => {
                refreshSessions();
                currentSessionId = null;
                currentSessionToken = null;
                document.getElementById('emptyChatState').style.display = 'flex';
                document.getElementById('chatInterface').style.display = 'none';
            }, 1000);
        } else {
            showNotification(data.message || 'Failed to end chat', 'error');
        }
    })
    .catch(error => {
        console.error('Error ending chat:', error);
        showNotification('Failed to end chat', 'error');
    })
    .finally(() => {
        endBtn.disabled = false;
        endBtn.innerHTML = originalText;
    });
}

function refreshSessions() {
    fetch('/api/chat/get-sessions.php')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateSessionsList(data.sessions);
            document.getElementById('activeCount').textContent = data.sessions.length;
            updateUnreadCount();
        }
    })
    .catch(error => {
        console.error('Error refreshing sessions:', error);
    });
}

function updateSessionsList(sessions) {
    const sessionsList = document.getElementById('sessionsList');
    
    if (sessions.length === 0) {
        sessionsList.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-chat-dots empty-icon"></i>
                <h6 class="empty-title">No Active Chats</h6>
                <p class="empty-text">New conversations will appear here when customers start chatting</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    sessions.forEach(session => {
        const isActive = currentSessionId && currentSessionId == session.id;
        const unreadClass = session.unread_count > 0 ? 'unread' : '';
        const activeClass = isActive ? 'active' : '';
        
        html += `
            <div class="session-item ${unreadClass} ${activeClass}" 
                 data-session-id="${session.id}" 
                 data-session-token="${escapeHtml(session.session_token)}" 
                 onclick="openChat(${session.id}, '${escapeHtml(session.session_token)}')">
                
                <div class="session-header">
                    <div class="customer-info">
                        <div class="customer-name">${escapeHtml(session.customer_name)}</div>
                        <div class="customer-email">${escapeHtml(session.customer_email)}</div>
                    </div>
                    <div class="session-badges">
                        ${session.unread_count > 0 ? `<div class="unread-badge">${session.unread_count}</div>` : ''}
                        <div class="status-dot status-${session.status}"></div>
                    </div>
                </div>
                
                ${session.last_message ? `
                    <div class="last-message">
                        ${escapeHtml(session.last_message)}
                    </div>
                ` : ''}
                
                <div class="session-footer">
                    <div class="session-time">
                        ${session.last_message_time ? 
                            formatTime(session.last_message_time) : 
                            formatTime(session.created_at)
                        }
                    </div>
                    ${session.staff_name ? `
                        <div class="assigned-staff">
                            <i class="bi bi-person-check-fill"></i>
                            <span>${escapeHtml(session.staff_name)}</span>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    sessionsList.innerHTML = html;
}

function updateUnreadCount() {
    const unreadBadges = document.querySelectorAll('.unread-badge');
    let totalUnread = 0;
    unreadBadges.forEach(badge => {
        totalUnread += parseInt(badge.textContent) || 0;
    });
    document.getElementById('unreadCount').textContent = totalUnread;
}

function markMessagesAsRead() {
    if (!currentSessionId) return;
    
    fetch('/api/chat/mark-read.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session_id: currentSessionId })
    })
    .catch(error => {
        console.error('Error marking messages as read:', error);
    });
}

function scrollToBottom() {
    const messagesContainer = document.getElementById('chatMessages');
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    
    const icon = type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill';
    
    notification.innerHTML = `
        <div class="notification-content">
            <i class="bi bi-${icon} notification-icon" style="color: var(--${type === 'success' ? 'success' : 'danger'})"></i>
            <div class="notification-text">${escapeHtml(message)}</div>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) reverse';
        setTimeout(() => notification.remove(), 400);
    }, 4000);
}

// Utility functions
function formatTime(dateString) {
    return new Date(dateString).toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: false 
    });
}

function formatDateTime(dateString) {
    return new Date(dateString).toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Cleanup
window.addEventListener('beforeunload', function() {
    if (pollInterval) clearInterval(pollInterval);
    if (refreshInterval) clearInterval(refreshInterval);
});

// Handle visibility changes
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        if (currentSessionId && !pollInterval) {
            startPolling();
        }
        if (!refreshInterval) {
            refreshInterval = setInterval(refreshSessions, 10000);
        }
    } else {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = setInterval(loadMessages, 30000);
        }
    }
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer-op.php'; ?>
