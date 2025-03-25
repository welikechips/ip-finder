#!/usr/bin/env python3
"""
Enhanced Command-line Tor Connection Checker
With JSON output capability
"""

import socket
import urllib.request
import json
import sys
import time
import subprocess
import platform
import re
import random
import os
from datetime import datetime

def print_colored(text, color):
    """Print colored text if supported by terminal"""
    colors = {
        'red': '\033[91m',
        'green': '\033[92m',
        'yellow': '\033[93m',
        'blue': '\033[94m',
        'magenta': '\033[95m',
        'cyan': '\033[96m',
        'white': '\033[97m',
        'reset': '\033[0m',
        'bold': '\033[1m'
    }

    # Check if we're in a terminal that supports colors
    if hasattr(sys.stdout, 'isatty') and sys.stdout.isatty():
        print(f"{colors.get(color, '')}{text}{colors['reset']}")
    else:
        print(text)

def get_local_ip():
    """Get local IP address"""
    try:
        hostname = socket.gethostname()
        local_ip = socket.gethostbyname(hostname)
        return local_ip
    except Exception as e:
        return f"Error: {e}"

def get_external_ip():
    """Get external IP from multiple services with fallbacks"""
    services = [
        ("https://api.ipify.org?format=json", "ip"),
        ("https://ipinfo.io/json", "ip"),
        ("https://api.myip.com", "ip")
    ]

    results = []
    for url, key in services:
        try:
            print_colored(f"Trying {url}...", "cyan")
            with urllib.request.urlopen(url, timeout=3) as response:
                data = json.loads(response.read().decode())
                if key in data:
                    ip = data[key]
                    service_name = url.split("//")[1].split("/")[0]
                    results.append({"service": service_name, "ip": ip})

                    # If it's ipinfo.io, get additional location data
                    if "ipinfo.io" in url:
                        # Extract any location data available
                        location_data = {}
                        for field in ["city", "region", "country", "loc", "org", "postal", "timezone"]:
                            if field in data:
                                location_data[field] = data[field]

                        if location_data:
                            results.append({"service": f"{service_name}_location", "data": location_data})
        except Exception as e:
            print_colored(f"Failed to connect to {url}: {e}", "yellow")

    return results

def check_tor_connection():
    """Check if connected to Tor"""
    try:
        print_colored("Checking Tor connection status...", "cyan")
        with urllib.request.urlopen("https://check.torproject.org/api/ip", timeout=5) as response:
            data = json.loads(response.read().decode())
            is_tor = data.get("IsTor", False)
            ip = data.get("IP", "unknown")
            return {"is_tor": is_tor, "ip": ip, "raw_response": data}
    except Exception as e:
        error_msg = str(e)
        print_colored(f"Error checking Tor status: {error_msg}", "yellow")
        return {"is_tor": None, "error": error_msg}

def get_dns_servers():
    """Get system DNS servers based on platform"""
    print_colored("Checking DNS servers...", "cyan")
    dns_servers = []
    error = None

    try:
        if platform.system() == "Windows":
            output = subprocess.check_output("ipconfig /all", shell=True, text=True)
            dns_servers = re.findall(r"DNS Servers[^\n]+: ([^\s]+)", output)
        elif platform.system() == "Darwin":  # macOS
            output = subprocess.check_output("scutil --dns", shell=True, text=True)
            dns_servers = re.findall(r"nameserver\[0*\d+\] : ([^\s]+)", output)
        else:  # Linux
            with open("/etc/resolv.conf", "r") as f:
                resolv_conf = f.read()
            dns_servers = re.findall(r"nameserver\s+([^\s]+)", resolv_conf)
    except Exception as e:
        error_msg = str(e)
        print_colored(f"Error getting DNS servers: {error_msg}", "yellow")
        error = error_msg

    # Classify each DNS server as local or public
    classified_servers = []
    for server in dns_servers:
        classified_servers.append({
            "ip": server,
            "is_local": is_private_ip(server)
        })

    return {"servers": classified_servers, "error": error}

def is_private_ip(ip):
    """Check if IP is a private/local network address"""
    private_patterns = [
        r'^10\.',                  # 10.0.0.0/8
        r'^172\.(1[6-9]|2[0-9]|3[0-1])\.',  # 172.16.0.0/12
        r'^192\.168\.',            # 192.168.0.0/16
        r'^127\.',                 # 127.0.0.0/8
        r'^169\.254\.',            # 169.254.0.0/16
        r'^::1$',                  # localhost IPv6
        r'^[fF][cCdD]',            # Unique local addresses in IPv6
    ]

    for pattern in private_patterns:
        if re.match(pattern, ip):
            return True
    return False

def test_dns_resolution():
    """Test DNS resolution for signs of leaks"""
    print_colored("Testing DNS resolution for leak detection...", "cyan")

    # List of domains to test
    test_domains = [
        "www.google.com",
        "www.facebook.com",
        "www.amazon.com",
        "www.wikipedia.org",
        "www.eff.org",
        "check.torproject.org",
        "dnsleaktest.com"
    ]

    # Randomly select some domains to test
    random.shuffle(test_domains)
    test_domains = test_domains[:4]  # Use only 4 domains to keep it quick

    results = []
    for domain in test_domains:
        try:
            # Get the IP address for each domain
            print_colored(f"  Resolving {domain}...", "cyan")
            ip = socket.gethostbyname(domain)
            results.append({"domain": domain, "resolved_ip": ip})
        except Exception as e:
            error_msg = str(e)
            print_colored(f"  Error resolving {domain}: {error_msg}", "yellow")
            results.append({"domain": domain, "error": error_msg})

    return results

def check_dns_through_tor():
    """Advanced check to see if DNS is likely going through Tor"""
    dns_data = get_dns_servers()
    dns_servers = [server["ip"] for server in dns_data["servers"]]

    # Check if DNS servers are local/private
    local_dns = any(is_private_ip(server) for server in dns_servers)

    if "127.0.0.1" in dns_servers:
        # 127.0.0.1 is good - likely using Tor for DNS
        status = True
        message = "DNS is set to localhost (127.0.0.1), which suggests DNS through Tor"
    elif not local_dns and dns_servers:
        # No local DNS servers is unusual but might be good
        status = None
        message = "No local DNS servers detected, unusual configuration"
    elif not dns_servers:
        status = None
        message = "Could not detect DNS servers"
    else:
        # Test some DNS resolutions to see if they match with public resolvers
        tor_data = check_tor_connection()
        if not tor_data.get("is_tor"):
            status = False
            message = "Not connected to Tor, so DNS is not through Tor"
        else:
            # If we get here, we're connected to Tor but using local DNS
            # For routers configured to use Tor, this might be normal
            status = None
            message = "Using local DNS servers while on Tor - might be normal if router is Tor-enabled"

    return {
        "dns_through_tor": status,
        "message": message,
        "dns_servers": dns_data["servers"]
    }

def get_recommendations(tor_status, dns_tor_status):
    """Generate recommendations based on test results"""
    recommendations = []

    if not tor_status.get("is_tor"):
        recommendations.append("Make sure Tor Browser or Tor service is running")
        recommendations.append("Check your proxy settings if using Tor as a SOCKS proxy")
    elif tor_status.get("is_tor"):
        if dns_tor_status.get("dns_through_tor") is not True:
            recommendations.append("Configure Tor to handle DNS requests:")
            recommendations.append("1. In Tor Browser: Settings → Connection → Check 'Proxy DNS when using SOCKS v5'")
            recommendations.append("2. Or add 'DNSPort 53' to your torrc file if using system Tor")
            recommendations.append("3. Or set your system DNS to 127.0.0.1 if Tor is configured to handle DNS")

        recommendations.append("Check for WebRTC leaks at: https://browserleaks.com/webrtc")
        recommendations.append("Check for DNS leaks at: https://dnsleaktest.com")

    if dns_tor_status.get("dns_through_tor") is None:
        recommendations.append("Consider further DNS leak testing at https://dnsleaktest.com")

    return recommendations

def run_all_tests():
    """Run all tests and return structured results"""
    results = {
        "timestamp": datetime.now().isoformat(),
        "system_info": {
            "platform": platform.system(),
            "release": platform.release(),
            "hostname": socket.gethostname()
        }
    }

    # Local IP
    results["local_ip"] = get_local_ip()

    # External IP
    results["external_ip"] = get_external_ip()

    # Tor status
    results["tor_status"] = check_tor_connection()

    # DNS servers
    results["dns_servers"] = get_dns_servers()

    # DNS Through Tor check
    results["dns_through_tor"] = check_dns_through_tor()

    # DNS resolution test
    results["dns_resolution"] = test_dns_resolution()

    # Recommendations
    results["recommendations"] = get_recommendations(
        results["tor_status"],
        results["dns_through_tor"]
    )

    return results

def save_results_to_json(results, file_path=None):
    """Save results to a JSON file"""
    if file_path is None:
        # Generate filename with timestamp if not provided
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        file_path = f"tor_check_{timestamp}.json"

    with open(file_path, 'w') as f:
        json.dump(results, f, indent=2)

    return file_path

def display_results(results):
    """Display results in a readable format"""
    print_colored("\n===== TOR CONNECTION CHECKER =====", "bold")
    print_colored("Running checks to verify your connection...\n", "white")

    # Local IP
    print_colored("LOCAL IP:", "bold")
    print(f"  {results['local_ip']}\n")

    # External IP
    print_colored("EXTERNAL IP:", "bold")
    if results['external_ip']:
        for service in results['external_ip']:
            if 'ip' in service:
                print(f"  {service['service']}: {service['ip']}")
            elif 'data' in service:
                location_str = ", ".join([f"{k}: {v}" for k, v in service['data'].items() if k in ['city', 'region', 'country']])
                if location_str:
                    print(f"  {service['service']}: {location_str}")
    else:
        print_colored("  Could not determine external IP", "red")
    print()

    # Tor status
    print_colored("TOR STATUS:", "bold")
    if results['tor_status'].get('is_tor') is True:
        print_colored("  ✅ You ARE connected to the Tor network", "green")
        if 'ip' in results['tor_status']:
            print(f"  Tor exit node IP: {results['tor_status']['ip']}")
    elif results['tor_status'].get('is_tor') is False:
        print_colored("  ❌ You are NOT connected to the Tor network", "red")
    else:
        print_colored("  ⚠️ Could not determine Tor connection status", "yellow")
        if 'error' in results['tor_status']:
            print(f"  Error: {results['tor_status']['error']}")
    print()

    # DNS servers
    print_colored("DNS SERVERS:", "bold")
    if results['dns_servers']['servers']:
        for server in results['dns_servers']['servers']:
            is_local = server['is_local']
            print(f"  {server['ip']}" + (" (local/private network)" if is_local else ""))
    else:
        print_colored("  Could not determine DNS servers", "yellow")
        if results['dns_servers']['error']:
            print(f"  Error: {results['dns_servers']['error']}")
    print()

    # DNS Through Tor check
    print_colored("DNS THROUGH TOR CHECK:", "bold")
    dns_tor_status = results['dns_through_tor']['dns_through_tor']
    dns_message = results['dns_through_tor']['message']
    if dns_tor_status is True:
        print_colored(f"  ✅ {dns_message}", "green")
    elif dns_tor_status is False:
        print_colored(f"  ❌ {dns_message}", "red")
    else:
        print_colored(f"  ⚠️ {dns_message}", "yellow")
    print()

    # DNS resolution test
    print_colored("DNS RESOLUTION TEST:", "bold")
    for entry in results['dns_resolution']:
        if 'resolved_ip' in entry:
            print(f"  {entry['domain']} → {entry['resolved_ip']}")
        else:
            print(f"  {entry['domain']} → Error: {entry['error']}")
    print()

    # Recommendations
    print_colored("RECOMMENDATIONS:", "bold")
    for recommendation in results['recommendations']:
        print(f"  - {recommendation}")

    print()
    print_colored(f"Check completed at: {results['timestamp']}", "white")
    print_colored("===============================\n", "bold")

def main():
    """Main function for the enhanced CLI Tor checker"""
    # Run all tests
    results = run_all_tests()

    # Display results
    display_results(results)

    # Save results to JSON
    json_file = save_results_to_json(results)
    print_colored(f"\nResults saved to: {os.path.abspath(json_file)}", "green")

    # Allow custom filename
    if len(sys.argv) > 1:
        custom_file = sys.argv[1]
        save_results_to_json(results, custom_file)
        print_colored(f"Results also saved to: {os.path.abspath(custom_file)}", "green")

if __name__ == "__main__":
    main()