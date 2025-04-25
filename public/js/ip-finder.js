/**
 * Enhanced IP Finder - Main JavaScript
 * Handles tab switching and client-side IP detection
 * Improved security and hostname resolution
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

    // Improved safety function for content display
    function safeSetContent(element, content) {
        if (element && content) {
            // Use textContent to prevent XSS
            element.textContent = content.toString().trim();
            return true;
        }
        return false;
    }

    // Function to attempt hostname lookup via client API
    function fetchHostname(ip) {
        const hostnameElement = document.getElementById('browser-hostname');

        // Create a safe request to our server endpoint that performs the lookup
        fetch(`hostname-lookup.php?ip=${encodeURIComponent(ip)}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data && data.hostname) {
                    safeSetContent(hostnameElement, data.hostname);
                } else {
                    const italicText = document.createElement('i');
                    italicText.textContent = 'No hostname found';
                    while (hostnameElement.firstChild) {
                        hostnameElement.removeChild(hostnameElement.firstChild);
                    }
                    hostnameElement.appendChild(italicText);
                }
            })
            .catch(error => {
                console.error('Error fetching hostname:', error);
                const italicText = document.createElement('i');
                italicText.textContent = 'Hostname lookup failed';
                while (hostnameElement.firstChild) {
                    hostnameElement.removeChild(hostnameElement.firstChild);
                }
                hostnameElement.appendChild(italicText);
            });
    }

    // Function to detect IP address using browser - enhanced security
    function detectBrowserIP() {
        const ipElement = document.getElementById('browser-ip');
        const hostnameElement = document.getElementById('browser-hostname');
        const loadingElement = document.getElementById('loading');
        const resultElement = document.getElementById('browser-ip-result');

        // Try to use the iframe first with improved security
        try {
            const ipFrame = document.getElementById('ip-frame');

            // Set up an event listener for when the iframe loads
            ipFrame.onload = function() {
                try {
                    // For simple text response, safely get content
                    const frameContent = ipFrame.contentDocument || ipFrame.contentWindow.document;
                    // Use textContent which is safer against XSS
                    const ipText = frameContent.body.textContent || frameContent.body.innerText;

                    if (ipText && ipText.trim().length > 0) {
                        // Validate IP format before displaying
                        const ipPattern = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
                        if (ipPattern.test(ipText.trim())) {
                            // Safely set the content
                            safeSetContent(ipElement, ipText.trim());

                            // Now try to fetch hostname for this IP
                            fetchHostname(ipText.trim());

                            // Use CSS classes to show/hide elements
                            loadingElement.classList.add('hidden');
                            resultElement.classList.remove('hidden');
                            return;
                        }
                    }
                    // If we reach here, something went wrong with the iframe content
                    fetchIPAddress();
                } catch (e) {
                    console.error('Error reading iframe content:', e);
                    // Continue with fetch approach as fallback
                    fetchIPAddress();
                }
            };

            // Back up with fetch API if iframe doesn't work after a timeout
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

    // Enhanced fetchIPAddress with hostname lookup and security improvements
    function fetchIPAddress() {
        const ipElement = document.getElementById('browser-ip');
        const hostnameElement = document.getElementById('browser-hostname');
        const loadingElement = document.getElementById('loading');
        const resultElement = document.getElementById('browser-ip-result');

        // Try first API
        fetch('https://api.ipify.org')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(ip => {
                // Validate IP format
                const ipPattern = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/;
                if (!ipPattern.test(ip.trim())) {
                    throw new Error('Invalid IP format received');
                }

                // Safely set content
                safeSetContent(ipElement, ip);

                // Attempt to fetch hostname for the IP
                fetchHostname(ip);

                // Use CSS classes to show/hide elements
                loadingElement.classList.add('hidden');
                loadingElement.classList.remove('visible');
                resultElement.classList.add('visible');
                resultElement.classList.remove('hidden');
            })
            .catch(error => {
                // Try another service as fallback
                fetch('https://api.ipify.org?format=json')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (!data.ip || !/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.test(data.ip)) {
                            throw new Error('Invalid IP format in JSON response');
                        }

                        // Safely set content
                        safeSetContent(ipElement, data.ip);

                        // Attempt to fetch hostname for the IP
                        fetchHostname(data.ip);

                        // Use CSS classes to show/hide elements
                        loadingElement.classList.add('hidden');
                        loadingElement.classList.remove('visible');
                        resultElement.classList.add('visible');
                        resultElement.classList.remove('hidden');
                    })
                    .catch(finalError => {
                        console.error('All IP detection methods failed:', finalError);

                        safeSetContent(ipElement, 'Detection failed');

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