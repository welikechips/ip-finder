# IP Tools Project - Docker Edition

This project contains two tools for checking and monitoring your IP address information, packaged in a Docker container for easy deployment:

1. **External IP Finder** - A PHP web application that displays your public IP address
2. **Tor Connection Checker** - A Python command-line tool that verifies if your connection is properly routing through the Tor network

## Table of Contents

- [Requirements](#requirements)
- [Quick Start](#quick-start)
- [Project Structure](#project-structure)
- [Using the External IP Finder](#using-the-external-ip-finder)
- [Using the Tor Connection Checker](#using-the-tor-connection-checker)
- [Understanding the Tor Checker Results](#understanding-the-tor-checker-results)
- [Security Notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Advanced Configuration](#advanced-configuration)

## Requirements

- Docker and Docker Compose
- Internet connection
- Tor Browser or Tor service (optional, only if you want to check Tor connections)

## Quick Start

1. **Clone this repository:**
   ```bash
   git clone https://github.com/yourusername/ip-tools.git
   cd ip-tools
   ```

2. **Build and start the Docker container:**
   ```bash
   docker-compose up -d
   ```

3. **Access the IP Finder web interface:**
   Open your browser and navigate to:
   ```
   http://localhost:8090/whats-my-ip.php
   ```

4. **Run the Tor Connection Checker:**
   ```bash
   docker exec -it ip-tools tor_check.py
   ```

## Project Structure

```
.
├── Dockerfile             # Docker configuration
├── docker-compose.yml     # Docker Compose configuration
├── whats-my-ip.php        # PHP External IP Finder
├── tor_check.py           # Python Tor Connection Checker
└── results/               # Directory for Tor checker JSON results
```

## Using the External IP Finder

The External IP Finder is accessible through your web browser after starting the Docker container:

1. Navigate to `http://localhost:8090/whats-my-ip.php`
2. The page displays your current external IP address
3. Click the "Refresh IP" button to update the displayed information

**Features:**
- Displays your current external IP address
- Uses multiple fallback APIs to ensure reliability
- Simple, responsive user interface
- "Refresh IP" button to update the displayed information
- Error handling when IP lookup fails

## Using the Tor Connection Checker

The Tor Connection Checker runs from the command line within the Docker container:

1. Run the script with the default output location:
   ```bash
   docker exec -it ip-tools tor_check.py
   ```

2. Or specify a custom output file in the shared volume:
   ```bash
   docker exec -it ip-tools tor_check.py /results/my_check.json
   ```

**Features:**
- Detects if you're connected to the Tor network
- Identifies your local and external IP addresses
- Lists your DNS servers and classifies them as local or public
- Checks if DNS requests are likely going through Tor (to prevent DNS leaks)
- Tests DNS resolution for common domains
- Provides recommendations based on the test results
- Saves detailed results to a JSON file

## Understanding the Tor Checker Results

The Tor Connection Checker provides detailed information about your connection:

- **Local IP**: Your device's IP address on the local network
- **External IP**: Your public IP address as seen by external services
- **Tor Status**: Whether you're successfully connected to the Tor network
- **DNS Servers**: The DNS servers your system is configured to use
- **DNS Through Tor Check**: Analysis of whether your DNS requests are likely going through Tor
- **DNS Resolution Test**: Results of resolving test domains
- **Recommendations**: Suggestions to improve your configuration

The tool saves a detailed report in JSON format with a timestamp. These reports are saved to the `results/` directory which is mapped as a volume in the Docker container.

## Security Notes

- These tools are for educational and diagnostic purposes only.
- The Docker container exposes the External IP Finder on port 8090 by default. Adjust this in `docker-compose.yml` if needed.
- The Tor Connection Checker does not modify your system settings; it only reports on your current configuration.
- For maximum privacy when using Tor, always use the official Tor Browser Bundle.
- When checking Tor connections, you'll need to:
    - Either configure your host system to use Tor
    - Or run Tor Browser on your host machine before running the check

## Troubleshooting

### Container Issues

- If the container doesn't start, check for port conflicts and adjust the port mapping in `docker-compose.yml`
- To view container logs:
  ```bash
  docker-compose logs
  ```

### External IP Finder Issues

- If you see "Error retrieving IP", check your internet connection and ensure the container has outbound access.
- If the page doesn't load, verify that your container is running:
  ```bash
  docker ps | grep ip-tools
  ```

### Tor Connection Checker Issues

- If the script shows you're not connected to Tor when you believe you should be, ensure Tor Browser or the Tor service is running on your host machine.
- Remember that the Docker container itself is not routing through Tor by default - it's checking if your underlying connection is using Tor.

## Advanced Configuration

### Custom Port Mapping

To change the port the IP Finder runs on, edit the `docker-compose.yml` file:

```yaml
ports:
  - "your_preferred_port:80"
```

### Using with a Tor Proxy Container

For a more complete setup, you can extend the Docker Compose configuration to include a Tor proxy container:

```yaml
services:
  ip-tools:
    # existing configuration...
    depends_on:
      - tor-proxy
  
  tor-proxy:
    image: dperson/torproxy
    container_name: tor-proxy
    restart: unless-stopped
```

With this setup, you can configure the Tor Connection Checker to use the Tor proxy container for its checks.

---

For more information on Tor and online privacy, visit [The Tor Project website](https://www.torproject.org/).