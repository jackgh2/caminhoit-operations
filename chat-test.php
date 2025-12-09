<!DOCTYPE html>
<html>
<head>
    <title>Chat API Test</title>
</head>
<body>
    <h1>Chat API Test</h1>
    
    <button onclick="testAPI()">Test API Connection</button>
    <div id="results" style="margin-top: 20px; padding: 20px; background: #f0f0f0; min-height: 200px;"></div>
    
    <script>
    function log(message) {
        document.getElementById('results').innerHTML += '<div>' + new Date().toLocaleTimeString() + ': ' + message + '</div>';
    }
    
    async function testAPI() {
        log('Testing API connection...');
        
        try {
            const response = await fetch('/chat-api.php?action=test');
            log('Response status: ' + response.status);
            
            if (response.ok) {
                const result = await response.json();
                log('SUCCESS: ' + JSON.stringify(result, null, 2));
            } else {
                log('ERROR: HTTP ' + response.status);
                const text = await response.text();
                log('Response text: ' + text);
            }
        } catch (error) {
            log('FETCH ERROR: ' + error.message);
        }
    }
    </script>
</body>
</html>