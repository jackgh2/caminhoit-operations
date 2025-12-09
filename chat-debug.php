<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Advanced Chat Debug Tool</title>
    <style>
        body { font-family: monospace; margin: 20px; }
        .test { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
        .success { background: #d4edda; }
        .error { background: #f8d7da; }
        .info { background: #d1ecf1; }
        button { padding: 10px 20px; margin: 5px; }
        textarea { width: 100%; height: 150px; font-family: monospace; }
        input[type="text"] { padding: 5px; margin: 5px; }
        pre { background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; }
    </style>
</head>
<body>
    <h1>Advanced Chat Debug Tool</h1>
    
    <div class="test info">
        <h3>Current Session Info</h3>
        <p><strong>PHP Session ID:</strong> <?= session_id() ?></p>
        <p><strong>User:</strong> <?= isset($_SESSION['user']) ? $_SESSION['user']['username'] : 'Not logged in' ?></p>
        <p><strong>Chat Session:</strong> <?= isset($_SESSION['chat_session']) ? 'Token: ' . substr($_SESSION['chat_session']['token'], 0, 8) . '...' : 'None' ?></p>
        <p><strong>Time:</strong> <?= date('Y-m-d H:i:s') ?></p>
    </div>
    
    <div class="test">
        <h3>1. Test Full Flow with Debug API</h3>
        <button onclick="testAdvancedFlow()">Run Advanced Flow Test</button>
        <div id="advancedResult"></div>
    </div>
    
    <div class="test">
        <h3>2. Debug Specific Session</h3>
        <input type="text" id="debugToken" placeholder="Session token to debug" style="width: 500px;">
        <button onclick="debugSession()">Debug Session</button>
        <div id="debugResult"></div>
    </div>
    
    <div class="test">
        <h3>3. Raw Response Log</h3>
        <textarea id="rawLog" readonly></textarea>
        <button onclick="clearLog()">Clear Log</button>
    </div>

    <script>
    let currentToken = null;
    
    function log(message) {
        const now = new Date().toLocaleTimeString();
        const logArea = document.getElementById('rawLog');
        logArea.value += `[${now}] ${message}\n`;
        logArea.scrollTop = logArea.scrollHeight;
        console.log(message);
    }
    
    function showResult(elementId, content, isError = false) {
        const el = document.getElementById(elementId);
        el.innerHTML = content;
        el.className = isError ? 'error' : 'success';
    }
    
    async function testAdvancedFlow() {
        log('=== STARTING ADVANCED FLOW TEST ===');
        
        try {
            // Step 1: Start session
            log('Step 1: Starting session...');
            const formData = new FormData();
            formData.append('action', 'start_session');
            formData.append('name', 'Advanced Test User');
            formData.append('email', 'advanced@example.com');
            
            const sessionResponse = await fetch('/chat-api-debug.php', {
                method: 'POST',
                body: formData
            });
            
            const sessionText = await sessionResponse.text();
            log(`Session response status: ${sessionResponse.status}`);
            log(`Session response: ${sessionText}`);
            
            if (!sessionResponse.ok) {
                throw new Error(`Session creation failed: ${sessionText}`);
            }
            
            const sessionResult = JSON.parse(sessionText);
            if (!sessionResult.success) {
                throw new Error(`Session creation failed: ${sessionResult.error}`);
            }
            
            currentToken = sessionResult.session_token;
            document.getElementById('debugToken').value = currentToken;
            
            log(`✓ Session created: ${currentToken.substr(0, 8)}...`);
            log(`Session verification: ${sessionResult.verification || 'unknown'}`);
            
            if (sessionResult.verification === 'failed') {
                log(`❌ Session verification failed immediately after creation!`);
                log(`DB Status: ${sessionResult.db_status || 'unknown'}`);
                showResult('advancedResult', `
                    <h4>❌ SESSION VERIFICATION FAILED</h4>
                    <p>Session was created but could not be retrieved immediately.</p>
                    <pre>${JSON.stringify(sessionResult, null, 2)}</pre>
                `, true);
                return;
            }
            
            // Wait a moment to ensure database consistency
            log('Waiting 1 second for database consistency...');
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // Step 2: Debug the session before sending message
            log('Step 1.5: Debugging session before send...');
            await debugSession(currentToken, false);
            
            // Step 2: Send message
            log('Step 2: Sending message...');
            const messageData = new FormData();
            messageData.append('action', 'send_message');
            messageData.append('session_token', currentToken);
            messageData.append('message', 'This is an advanced test message');
            
            const messageResponse = await fetch('/chat-api-debug.php', {
                method: 'POST',
                body: messageData
            });
            
            const messageText = await messageResponse.text();
            log(`Message response status: ${messageResponse.status}`);
            log(`Message response: ${messageText}`);
            
            if (!messageResponse.ok) {
                throw new Error(`Message send failed: ${messageText}`);
            }
            
            const messageResult = JSON.parse(messageText);
            if (!messageResult.success) {
                log(`❌ Message send failed: ${messageResult.error}`);
                
                // Debug the session after failure
                log('Step 2.5: Debugging session after failure...');
                await debugSession(currentToken, false);
                
                throw new Error(`Message send failed: ${messageResult.error}`);
            }
            
            log('✓ Message sent successfully');
            
            // Success!
            showResult('advancedResult', `
                <h4>✅ ADVANCED FLOW TEST PASSED!</h4>
                <p><strong>Session Token:</strong> ${currentToken.substr(0, 8)}...</p>
                <p><strong>Session Verification:</strong> ${sessionResult.verification}</p>
                <p><strong>Message Result:</strong> ${messageResult.message_id ? 'Message ID ' + messageResult.message_id : 'Success'}</p>
                <pre>${JSON.stringify({session: sessionResult, message: messageResult}, null, 2)}</pre>
            `);
            
        } catch (error) {
            log(`❌ Advanced flow test failed: ${error.message}`);
            showResult('advancedResult', `<h4>❌ ADVANCED FLOW TEST FAILED</h4><p>${error.message}</p>`, true);
        }
    }
    
    async function debugSession(token = null, showResult = true) {
        const sessionToken = token || document.getElementById('debugToken').value;
        
        if (!sessionToken) {
            if (showResult) {
                showResult('debugResult', 'Please provide session token', true);
            }
            return;
        }
        
        log(`Debugging session: ${sessionToken.substr(0, 8)}...`);
        
        try {
            const response = await fetch(`/chat-api-debug.php?action=debug_session&session_token=${encodeURIComponent(sessionToken)}`);
            const text = await response.text();
            
            log(`Debug response status: ${response.status}`);
            log(`Debug response: ${text}`);
            
            if (response.ok) {
                const result = JSON.parse(text);
                
                if (showResult) {
                    showResult('debugResult', `
                        <h4>Session Debug Results</h4>
                        <pre>${JSON.stringify(result.debug, null, 2)}</pre>
                    `);
                }
                
                const debug = result.debug;
                log(`Database session found: ${debug.database_session ? 'YES' : 'NO'}`);
                log(`Manager session found: ${debug.manager_session ? 'YES' : 'NO'}`);
                
                if (debug.database_session && !debug.manager_session) {
                    log(`❌ ISSUE FOUND: Session exists in database but ChatManager can't find it`);
                    log(`Database status: ${debug.database_session.status}`);
                }
                
            } else {
                if (showResult) {
                    showResult('debugResult', `Debug failed: ${text}`, true);
                }
            }
        } catch (error) {
            log(`Debug session error: ${error.message}`);
            if (showResult) {
                showResult('debugResult', `Error: ${error.message}`, true);
            }
        }
    }
    
    function clearLog() {
        document.getElementById('rawLog').value = '';
    }
    </script>
</body>
</html>