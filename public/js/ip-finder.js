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
        detectWebRTCLeak();
    });

    // --- Theme switch: Auto -> Light -> Dark, persisted client-side in localStorage ---
    (function () {
        const btn = document.getElementById('theme-toggle');
        if (!btn) {
            return;
        }
        const LABELS = { auto: '◐ Auto', light: '☀ Light', dark: '☾ Dark' };
        const readMode = function () {
            let t = null;
            try {
                t = localStorage.getItem('theme');
            } catch (e) {}
            return (t === 'light' || t === 'dark') ? t : 'auto';
        };
        const applyMode = function (mode) {
            if (mode === 'auto') {
                document.documentElement.removeAttribute('data-theme');
                try { localStorage.removeItem('theme'); } catch (e) {}
            } else {
                document.documentElement.setAttribute('data-theme', mode);
                try { localStorage.setItem('theme', mode); } catch (e) {}
            }
            btn.textContent = LABELS[mode];
            btn.setAttribute('title', 'Theme: ' + mode.charAt(0).toUpperCase() + mode.slice(1) + ' (click to change)');
        };
        applyMode(readMode());
        btn.addEventListener('click', function () {
            const order = ['auto', 'light', 'dark'];
            applyMode(order[(order.indexOf(readMode()) + 1) % order.length]);
        });
    })();

    // --- Copy-to-clipboard for IP values ---
    const fallbackCopy = function (text, done) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.className = 'copy-helper';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            if (done) {
                done();
            }
        } catch (e) {}
    };
    Array.prototype.forEach.call(document.querySelectorAll('.copy-btn'), function (btn) {
        btn.addEventListener('click', function () {
            const target = document.getElementById(btn.getAttribute('data-copy'));
            if (!target) {
                return;
            }
            const text = (target.textContent || '').trim();
            if (!text) {
                return;
            }
            const flash = function () {
                const prev = btn.textContent;
                btn.textContent = '✓';
                btn.classList.add('copied');
                setTimeout(function () {
                    btn.textContent = prev;
                    btn.classList.remove('copied');
                }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(flash).catch(function () { fallbackCopy(text, flash); });
            } else {
                fallbackCopy(text, flash);
            }
        });
    });

    // --- WebRTC leak check: surface IPs that WebRTC exposes (they can bypass a VPN).
    //     Runs entirely in the browser; nothing is sent to the server. ---
    let webrtcChecked = false;
    function detectWebRTCLeak() {
        const el = document.getElementById('webrtc-result');
        if (!el || webrtcChecked) {
            return;
        }
        webrtcChecked = true;

        if (!window.RTCPeerConnection) {
            setWebrtc(el, 'ok', 'WebRTC is not available in this browser — nothing to leak.');
            return;
        }

        const knownEl = document.getElementById('server-ip-value');
        const knownIp = knownEl ? (knownEl.textContent || '').trim() : '';
        const found = {};
        let pc;
        try {
            pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });
        } catch (e) {
            setWebrtc(el, 'ok', 'WebRTC is blocked — nothing exposed. Good for privacy.');
            return;
        }

        let done = false;
        try {
            pc.createDataChannel('leak');
        } catch (e) {}

        pc.onicecandidate = function (e) {
            if (!e || !e.candidate) { finish(); return; }
            const ips = extractIps(e.candidate.candidate || '');
            for (let i = 0; i < ips.length; i++) { found[ips[i]] = true; }
            render(false);
        };
        pc.createOffer().then(function (offer) { return pc.setLocalDescription(offer); }).catch(function () {});
        setTimeout(finish, 3000);

        function finish() {
            if (done) { return; }
            done = true;
            try { pc.close(); } catch (e) {}
            render(true);
        }

        function render(complete) {
            const ips = Object.keys(found);
            if (!complete && ips.length === 0) { el.textContent = 'Checking…'; return; }
            if (ips.length === 0) {
                setWebrtc(el, 'ok', '✓ No IP addresses exposed via WebRTC.');
                return;
            }
            const privates = ips.filter(isPrivateIp);
            const publics = ips.filter(function (ip) { return !isPrivateIp(ip); });
            const leaked = publics.filter(function (ip) { return knownIp && ip !== knownIp; });

            if (privates.length === 0 && leaked.length === 0) {
                const shown = publics.length ? publics.join(', ') : ips.join(', ');
                setWebrtc(el, 'ok', '✓ WebRTC exposes only your known IP (' + shown + ') — no leak detected.');
                return;
            }
            let msg = '';
            if (privates.length) { msg += '⚠️ Local network IP exposed: ' + privates.join(', ') + '. '; }
            if (leaked.length) { msg += '⚠️ A different public IP is visible via WebRTC: ' + leaked.join(', ') + ' — this can bypass a VPN. '; }
            setWebrtc(el, 'warn', msg.trim());
        }
    }

    function setWebrtc(el, level, text) {
        el.textContent = text;
        el.classList.remove('webrtc-ok', 'webrtc-warn');
        el.classList.add(level === 'warn' ? 'webrtc-warn' : 'webrtc-ok');
    }

    // Extract plausible IPv4/IPv6 addresses from an ICE candidate string (skips mDNS .local).
    function extractIps(candidate) {
        if (candidate.indexOf('.local') !== -1) { return []; }
        const out = [];
        const v4 = candidate.match(/(?:\d{1,3}\.){3}\d{1,3}/g) || [];
        for (let i = 0; i < v4.length; i++) {
            if (isValidV4(v4[i]) && v4[i] !== '0.0.0.0') { out.push(v4[i]); }
        }
        const v6 = candidate.match(/[a-f0-9]{0,4}(?::[a-f0-9]{0,4}){3,7}/gi) || [];
        for (let j = 0; j < v6.length; j++) {
            if (v6[j].indexOf(':') !== -1 && v6[j] !== '::') { out.push(v6[j].toLowerCase()); }
        }
        return out;
    }

    function isValidV4(ip) {
        const parts = ip.split('.');
        if (parts.length !== 4) { return false; }
        for (let i = 0; i < 4; i++) {
            const n = parseInt(parts[i], 10);
            if (isNaN(n) || n < 0 || n > 255) { return false; }
        }
        return true;
    }

    function isPrivateIp(ip) {
        if (ip.indexOf(':') !== -1) {
            return /^(fe80:|fc|fd|::1)/i.test(ip);
        }
        return /^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(ip);
    }

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
                    replaceWithItalic(hostnameElement, 'No hostname found');
                }
            })
            .catch(error => {
                console.error('Error fetching hostname:', error);
                replaceWithItalic(hostnameElement, 'Hostname lookup failed');
            });
    }

    // Detect the IP the browser's own request presents, by asking our SAME-ORIGIN endpoint,
    // which echoes the IP it sees. No third-party service, no iframe. This is a live re-check:
    // it can differ from Server Detection above if the network changed since the page loaded
    // (e.g. a VPN/proxy toggled in the browser); the WebRTC check covers hidden/leaked IPs.
    function detectBrowserIP() {
        const ipElement = document.getElementById('browser-ip');
        const hostnameElement = document.getElementById('browser-hostname');
        const loadingElement = document.getElementById('loading');
        const resultElement = document.getElementById('browser-ip-result');

        const reveal = function () {
            loadingElement.classList.add('hidden');
            loadingElement.classList.remove('visible');
            resultElement.classList.add('visible');
            resultElement.classList.remove('hidden');
        };

        fetch('?format=text', {
            method: 'GET',
            headers: { 'Accept': 'text/plain', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('Network response was not ok'); }
                return response.text();
            })
            .then(function (text) {
                const ip = (text || '').trim();
                if (!isDisplayableIp(ip)) { throw new Error('Invalid IP received'); }
                safeSetContent(ipElement, ip);
                fetchHostname(ip);
                reveal();
            })
            .catch(function (error) {
                console.error('Browser IP detection failed:', error);
                safeSetContent(ipElement, 'Detection failed');
                replaceWithItalic(hostnameElement, 'Detection failed');
                reveal();
            });
    }

    // Accept IPv4 (dotted) or IPv6 (hex + colons). The value already came validated from our own
    // server, so this is a display sanity check, not the security boundary.
    function isDisplayableIp(s) {
        if (!s || s.length > 45) { return false; }
        if (/^(\d{1,3}\.){3}\d{1,3}$/.test(s)) { return true; }
        return s.indexOf(':') !== -1 && /^[0-9a-fA-F:]+$/.test(s);
    }

    // Replace an element's contents with a single italic message (textContent — no HTML injection).
    function replaceWithItalic(el, message) {
        if (!el) { return; }
        while (el.firstChild) { el.removeChild(el.firstChild); }
        const italic = document.createElement('i');
        italic.textContent = message;
        el.appendChild(italic);
    }
});