/**
 * Enhanced IP Finder - Main JavaScript
 * Handles tab switching and client-side IP detection
 */

// Wait for DOM to be loaded
document.addEventListener('DOMContentLoaded', function() {
    // Get references to tabs and content
    const serverTab = document.getElementById('server-tab');
    const clientTab = document.getElementById('client-tab');
    const serverContent = document.getElementById('server-side');
    const clientContent = document.getElementById('client-side');

    // Add event listeners to tabs
    serverTab.addEventListener('click', function() {
        switchTab('server-side');
    });

    clientTab.addEventListener('click', function() {
        switchTab('client-side');
        detectBrowserIP();
    });

    // Function to switch tabs
    function switchTab(tabId) {
        // Hide all tab contents
        serverContent.classList.remove('active');
        clientContent.classList.remove('active');

        // Remove active class from all tabs
        serverTab.classList.remove('active');
        clientTab.classList.remove('active');

        // Show the selected tab content and activate tab
        if (tabId === 'server-side') {
            serverContent.classList.add('active');
            serverTab.classList.add('active');
        } else if (tabId === 'client-side') {
            clientContent.classList.add('active');
            clientTab.classList.add('active');
        }
    }

    // Function to detect IP address using browser
    function detectBrowserIP() {
        const ipElement = document.getElementById('browser-ip');
        const hostnameElement = document.getElementById('browser-hostname');
        const loadingElement = document.getElementById('loading');
        const resultElement = document.getElementById('browser-ip-result');

        // Try to use the iframe first
        try {
            const ipFrame = document.getElementById('ip-frame');

            // Set up an event listener for when the iframe loads
            ipFrame.onload = function() {
                try {
                    // For simple text response
                    const frameContent = ipFrame.contentDocument || ipFrame.contentWindow.document;
                    const ipText = frameContent.body.innerText || frameContent.body.textContent;

                    if (ipText && ipText.trim().length > 0) {
                        // Safely set the content
                        ipElement.textContent = ipText.trim();

                        // Create an italic element for hostname message
                        const italicText = document.createElement('i');
                        italicText.textContent = 'Hostname lookup not available in browser';

                        // Clear existing content and append the new element
                        while (hostnameElement.firstChild) {
                            hostnameElement.removeChild(hostnameElement.firstChild);
                        }
                        hostnameElement.appendChild(italicText);

                        // Use CSS classes to show/hide elements
                        loadingElement.classList.add('hidden');
                        resultElement.classList.remove('hidden');
                        return;
                    }
                } catch (e) {
                    console.error('Error reading iframe content:', e);
                    // Continue with fetch approach below
                }
            };

            // Back up with fetch API if iframe doesn't work
            setTimeout(function() {
                if (!loadingElement.classList.contains('hidden')) {
                    fetchIPAddress();
                }
            }, 3000);
        } catch (e) {
            console.error('Error with iframe:', e);
            fetchIPAddress();
        }
    }

    // Fallback to fetch API if iframe doesn't work
    function fetchIPAddress() {
        const ipElement = document.getElementById('browser-ip');
        const hostnameElement = document.getElementById('browser-hostname');
        const loadingElement = document.getElementById('loading');
        const resultElement = document.getElementById('browser-ip-result');

        fetch('https://api.ipify.org')
            .then(response => response.text())
            .then(ip => {
                // Safely set content
                ipElement.textContent = ip;

                // Create an italic element for hostname message
                const italicText = document.createElement('i');
                italicText.textContent = 'Hostname lookup not available in browser';

                // Clear existing content and append the new element
                while (hostnameElement.firstChild) {
                    hostnameElement.removeChild(hostnameElement.firstChild);
                }
                hostnameElement.appendChild(italicText);

                // Use CSS classes to show/hide elements
                loadingElement.classList.add('hidden');
                loadingElement.classList.remove('visible');
                resultElement.classList.add('visible');
                resultElement.classList.remove('hidden');
            })
            .catch(error => {
                // Try another service
                fetch('https://api.ipify.org?format=json')
                    .then(response => response.json())
                    .then(data => {
                        // Safely set content
                        ipElement.textContent = data.ip;

                        // Create an italic element for hostname message
                        const italicText = document.createElement('i');
                        italicText.textContent = 'Hostname lookup not available in browser';

                        // Clear existing content and append the new element
                        while (hostnameElement.firstChild) {
                            hostnameElement.removeChild(hostnameElement.firstChild);
                        }
                        hostnameElement.appendChild(italicText);

                        // Use CSS classes to show/hide elements
                        loadingElement.classList.add('hidden');
                        loadingElement.classList.remove('visible');
                        resultElement.classList.add('visible');
                        resultElement.classList.remove('hidden');
                    })
                    .catch(error => {
                        ipElement.textContent = 'Detection failed';

                        // Create an italic element for hostname message
                        const italicText = document.createElement('i');
                        italicText.textContent = 'Detection failed';

                        // Clear existing content and append the new element
                        while (hostnameElement.firstChild) {
                            hostnameElement.removeChild(hostnameElement.firstChild);
                        }
                        hostnameElement.appendChild(italicText);

                        // Use CSS classes to show/hide elements
                        loadingElement.classList.add('hidden');
                        loadingElement.classList.remove('visible');
                        resultElement.classList.add('visible');
                        resultElement.classList.remove('hidden');
                    });
            });
    }
});