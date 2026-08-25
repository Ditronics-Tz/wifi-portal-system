# Commercial Wi-Fi Voucher Anti-Sharing Architecture

**Document Type:** Technical Design Specification\
**Version:** 1.0\
**Date:** 25 August 2026\
**Status:** Proposed Production Architecture

------------------------------------------------------------------------

## 1. Purpose

This document defines a production-oriented architecture for a
commercial Wi-Fi voucher platform where customers purchase Internet
access using vouchers and authenticate through a captive portal.

The primary security objective is to reduce and detect unauthorized
sharing of a customer's Internet session through:

-   Mobile hotspot/tethering
-   Voucher reuse on multiple devices
-   Direct client-to-client communication
-   Session hijacking or unauthorized reuse
-   Excessive concurrent sessions

> **Important:** A network operator cannot reliably force a customer's
> phone operating system to disable its hotspot. The correct approach is
> to enforce access policy at the gateway and wireless infrastructure,
> then detect and block suspicious downstream sharing where technically
> possible.

------------------------------------------------------------------------

# 2. Business Requirements

The Wi-Fi platform should support:

1.  Voucher generation.
2.  Voucher purchase/payment.
3.  Captive portal authentication.
4.  Time-based packages.
5.  Bandwidth-based packages.
6.  One-device or controlled-device access.
7.  Session expiration.
8.  Concurrent-session limits.
9.  Client/AP isolation.
10. Detection of suspicious tethering.
11. Administrative monitoring.
12. Automatic session termination when policy is violated.

------------------------------------------------------------------------

# 3. High-Level Architecture

``` text
                         INTERNET
                            |
                            v
                   +------------------+
                   |     ISP / WAN    |
                   +--------+---------+
                            |
                            v
                   +------------------+
                   |   MikroTik /     |
                   |   Network        |
                   |   Gateway        |
                   +--------+---------+
                            |
             +--------------+--------------+
             |                             |
             v                             v
      +-------------+               +-------------+
      | Captive     |               | Management  |
      | Portal      |               | / API       |
      +------+------+               +------+------+
             |                             |
             |                             v
             |                      +-------------+
             |                      | Application |
             |                      | Backend     |
             |                      +------+------+
             |                             |
             |                      +------+------+
             |                      | PostgreSQL  |
             |                      +-------------+
             |
             v
      +----------------+
      | Wi-Fi Access    |
      | Points          |
      +--------+--------+
               |
       +-------+-------+
       |       |       |
       v       v       v
    Client  Client   Client
      A       B        C
```

------------------------------------------------------------------------

# 4. Core Security Model

The anti-sharing system should use multiple controls instead of relying
on a single mechanism.

``` text
                  Voucher Authentication
                           |
                           v
                  Session Registration
                           |
             +-------------+-------------+
             |             |             |
             v             v             v
       Device/session   Client       Firewall /
          limits       isolation     traffic rules
             |             |             |
             +-------------+-------------+
                           |
                           v
                  Tethering Detection
                           |
                           v
                  Policy Enforcement
                           |
                    +------+------+
                    |             |
                  ALLOW          BLOCK
```

------------------------------------------------------------------------

# 5. Voucher Lifecycle

## 5.1 Purchase

``` text
Customer
   |
   v
Select package
   |
   v
Payment
   |
   v
Payment confirmation
   |
   v
Voucher generated
```

A voucher should contain or reference:

-   Unique voucher code
-   Package
-   Duration
-   Bandwidth limit
-   Data limit, if applicable
-   Status
-   Creation time
-   Expiration time
-   Device/session policy

Example:

``` text
Voucher
------------------------------
Code:          ABC123XYZ
Package:       10 Mbps / 24h
Duration:      24 hours
Status:        UNUSED
Device Limit:  1
Created At:    2026-08-25 10:00
Expires At:    2026-08-26 10:00
```

------------------------------------------------------------------------

# 6. Captive Portal Authentication

The customer connects to the Wi-Fi SSID.

``` text
Customer Device
       |
       v
Connect to SSID
       |
       v
Receive DHCP address
       |
       v
Open website
       |
       v
Captive Portal
       |
       v
Enter Voucher
       |
       v
Backend / Gateway Validation
```

The system verifies:

1.  Voucher exists.
2.  Voucher is active.
3.  Voucher has not expired.
4.  Voucher has not exceeded its device/session limit.
5.  The requested session complies with the package policy.

------------------------------------------------------------------------

# 7. Voucher-to-Session Binding

A voucher should be associated with an authenticated network session.

Example:

``` text
Voucher
   |
   +-- ABC123XYZ
         |
         +-- User session
         |
         +-- Client MAC
         |
         +-- Assigned IP
         |
         +-- Gateway session ID
         |
         +-- Login time
         |
         +-- Expiration time
```

Example database representation:

``` text
voucher_sessions
-------------------------------
id
voucher_id
client_mac
client_ip
gateway_session_id
login_at
expires_at
last_seen_at
status
```

Do not depend exclusively on MAC addresses because modern devices can
use randomized/private MAC addresses.

The session should be the primary authorization object, with MAC/IP and
gateway information used as supporting identifiers.

------------------------------------------------------------------------

# 8. One-Device Policy

For a single-device voucher:

``` text
Voucher ABC123
       |
       v
Phone A authenticates
       |
       v
SESSION ACTIVE
```

A second device attempting the same voucher:

``` text
Laptop B
   |
   v
ABC123
   |
   v
Existing session detected
   |
   v
REJECT
```

Possible policies:

### Policy A --- Strict

Only one active device is allowed.

### Policy B --- Device Lock

The voucher becomes associated with the first authenticated device.

### Policy C --- Controlled Re-login

A customer can disconnect the first device and move the voucher to
another device.

For a commercial hotspot, **Policy A or B** is usually the strongest
starting point.

------------------------------------------------------------------------

# 9. Client/AP Isolation

Enable client isolation on the wireless infrastructure.

Possible names include:

-   AP Isolation
-   Client Isolation
-   Station Isolation
-   Wireless Isolation
-   SSID Isolation

The desired behavior is:

``` text
              Access Point
                    |
          +---------+---------+
          |         |         |
        Client A Client B Client C
          X         X         X
       Direct client-to-client communication blocked
```

This protects customers from directly communicating with other wireless
customers.

However:

> Client isolation does not by itself completely prevent
> hotspot/tethering.

------------------------------------------------------------------------

# 10. Hotspot/Tethering Problem

Consider:

``` text
                  Your Wi-Fi
                      |
                      v
                Customer Phone
                      |
              Phone Hotspot
                 /                          /                           v              v
           Laptop B        Phone C
```

Your gateway may see the customer's phone as the primary authenticated
client while the downstream devices are hidden behind NAT.

Therefore:

``` text
MAC binding alone
        !=
Complete hotspot prevention
```

The system should instead combine several network controls.

------------------------------------------------------------------------

# 11. Tethering Detection Strategy

The gateway can monitor traffic and session behavior for indicators of
downstream devices.

Potential indicators include:

-   Multiple downstream IP addresses
-   NAT behavior
-   Unexpected ARP/DHCP patterns
-   Abnormal TTL characteristics
-   Traffic patterns inconsistent with a single endpoint
-   Multiple client identities appearing behind one authenticated
    session
-   Excessive concurrent connections
-   Device/session behavior inconsistent with the package policy

A conceptual detection pipeline:

``` text
Authenticated Session
        |
        v
Traffic Monitoring
        |
        v
Behavior Analysis
        |
        +---- Normal ----> Continue
        |
        +---- Suspicious -> Apply Policy
                              |
                     +--------+--------+
                     |                 |
                  Warn/Log           Block
```

Detection should be treated as **best-effort**, not as a mathematically
guaranteed way to identify every tethering configuration.

------------------------------------------------------------------------

# 12. Gateway Enforcement

The gateway should enforce:

-   Authentication
-   Session expiration
-   Bandwidth limits
-   Connection limits
-   Firewall policy
-   Concurrent-session policy
-   Client isolation where supported
-   Automatic disconnect
-   Logging

Example:

``` text
Voucher ABC123
------------------------------
Bandwidth:       5 Mbps
Duration:        2 Hours
Device Limit:    1
Session Limit:   1
Status:          ACTIVE
```

If a policy violation is detected:

``` text
Detection
    |
    v
Create security event
    |
    v
Terminate session
    |
    v
Mark event
    |
    v
Notify administrator
```

------------------------------------------------------------------------

# 13. Recommended Network Technology

For a commercial deployment, a practical architecture is:

## Gateway

**MikroTik Router**

Responsibilities:

-   Hotspot/captive portal
-   DHCP
-   Firewall
-   NAT
-   Bandwidth management
-   Session control
-   User management
-   API integration

## Access Points

Use managed APs supporting:

-   Client isolation
-   VLANs
-   Multiple SSIDs
-   Central management
-   Sufficient client capacity

## Application Backend

Recommended logical components:

``` text
Frontend
   |
   v
Backend API
   |
   +---- Voucher Service
   |
   +---- Payment Service
   |
   +---- Network Controller
   |
   +---- Session Service
   |
   +---- Monitoring Service
   |
   v
PostgreSQL
```

------------------------------------------------------------------------

# 14. Application Architecture

A production application can be organized as follows:

``` text
wifi-platform/
|
+-- frontend/
|   +-- captive-portal/
|   +-- admin-dashboard/
|
+-- backend/
|   +-- auth/
|   +-- vouchers/
|   +-- payments/
|   +-- sessions/
|   +-- network/
|   +-- monitoring/
|   +-- notifications/
|
+-- infrastructure/
|   +-- docker/
|   +-- nginx/
|   +-- monitoring/
|
+-- docs/
|   +-- network-architecture.md
|   +-- api.md
|   +-- security.md
```

------------------------------------------------------------------------

# 15. Backend Responsibilities

The backend should not directly replace the router.

Instead:

``` text
Backend
   |
   | API
   v
Network Gateway
```

The backend manages business logic.

The gateway manages real-time network enforcement.

### Backend handles

-   Customers
-   Payments
-   Packages
-   Voucher generation
-   Voucher status
-   Sessions
-   Reports
-   Administrators
-   Security events

### Gateway handles

-   Client authentication
-   IP assignment
-   NAT
-   Firewall
-   Bandwidth
-   Session termination
-   Real-time network traffic

------------------------------------------------------------------------

# 16. Suggested Database Model

A simplified PostgreSQL model:

``` text
customers
----------------
id
name
phone
created_at


packages
----------------
id
name
duration
speed_limit
data_limit
device_limit
price
active


vouchers
----------------
id
code
package_id
status
created_at
activated_at
expires_at


sessions
----------------
id
voucher_id
client_mac
client_ip
gateway_session_id
started_at
last_seen_at
expires_at
status


security_events
----------------
id
session_id
event_type
severity
metadata
created_at
resolved_at
```

------------------------------------------------------------------------

# 17. Security Event Examples

Possible event types:

``` text
VOUCHER_REUSE
MULTIPLE_DEVICE
SUSPICIOUS_TETHERING
SESSION_LIMIT
EXPIRED_VOUCHER
INVALID_VOUCHER
ABNORMAL_TRAFFIC
```

Example:

``` text
Security Event
-----------------------------------
Type:       SUSPICIOUS_TETHERING
Session:    92831
Voucher:    ABC123XYZ
Client IP:  10.10.20.45
Severity:   HIGH
Action:     SESSION_TERMINATED
Time:       2026-08-25 12:41
```

------------------------------------------------------------------------

# 18. VLAN Architecture

For a larger deployment, separate management and customer traffic.

``` text
                 MikroTik
                    |
          +---------+---------+
          |                   |
       VLAN 10             VLAN 20
     Management            Customers
          |                   |
       Admin/APs          Wi-Fi Clients
```

Example:

``` text
VLAN 10 = Network Management
VLAN 20 = Customer Internet
VLAN 30 = Optional Guest/Isolation Network
```

Customer traffic should not be able to access router management
interfaces.

------------------------------------------------------------------------

# 19. Firewall Principles

Customer Wi-Fi should generally be denied access to:

``` text
Router management
Admin dashboard
Access point management
Other customer clients
Internal infrastructure
Private LAN services
```

Allowed traffic should primarily be:

``` text
Customer
   |
   +---- DNS
   +---- DHCP
   +---- Captive Portal
   +---- Internet
```

Management access should come from a separate trusted network.

------------------------------------------------------------------------

# 20. Recommended Production Flow

The complete flow should be:

``` text
                    CUSTOMER
                       |
                       v
                Buy Package
                       |
                       v
                    PAYMENT
                       |
                       v
                Payment Confirmed
                       |
                       v
                Generate Voucher
                       |
                       v
                Connect to Wi-Fi
                       |
                       v
                Captive Portal
                       |
                       v
                Enter Voucher
                       |
                       v
              Validate Voucher
                       |
             +---------+---------+
             |                   |
           INVALID             VALID
             |                   |
             v                   v
           BLOCK          Create Session
                               |
                               v
                       Apply Package Rules
                               |
                 +-------------+-------------+
                 |             |             |
                 v             v             v
             Bandwidth     Device Limit   Expiration
                 |             |             |
                 +-------------+-------------+
                               |
                               v
                           INTERNET
```

------------------------------------------------------------------------

# 21. Anti-Sharing Stack

The recommended protection stack is:

``` text
                 ANTI-SHARING
                      |
        +-------------+-------------+
        |             |             |
        v             v             v
   Voucher Lock   Session Limit   Client Isolation
        |             |             |
        +-------------+-------------+
                      |
                      v
               Firewall Rules
                      |
                      v
              Traffic Monitoring
                      |
                      v
            Tethering Detection
                      |
                      v
             Automatic Blocking
```

No single layer should be considered sufficient.

------------------------------------------------------------------------

# 22. What the System Can and Cannot Guarantee

## Can strongly enforce

-   Voucher expiration
-   One active session
-   Voucher reuse prevention
-   Bandwidth limits
-   Time limits
-   Client isolation
-   Network segmentation
-   Firewall restrictions
-   Automatic session termination

## Can detect with varying reliability

-   Hotspot/tethering
-   Multiple downstream devices
-   Suspicious NAT behavior
-   Abnormal connection patterns

## Cannot guarantee

The network cannot guarantee that every possible phone, operating
system, VPN, USB tethering configuration, or future technology will
always be identifiable as tethering.

Therefore, the architecture should be described as **anti-sharing
controls and detection**, not absolute hotspot prevention.

------------------------------------------------------------------------

# 23. Recommended Implementation Roadmap

## Phase 1 --- Core Voucher System

-   [ ] Voucher generation
-   [ ] Packages
-   [ ] Payment integration
-   [ ] Captive portal
-   [ ] Voucher validation
-   [ ] Session creation
-   [ ] Expiration

## Phase 2 --- Network Enforcement

-   [ ] MikroTik integration
-   [ ] One active session per voucher
-   [ ] Bandwidth profiles
-   [ ] Session termination
-   [ ] Firewall rules
-   [ ] Client isolation

## Phase 3 --- Anti-Sharing

-   [ ] Session/device monitoring
-   [ ] Traffic monitoring
-   [ ] Tethering indicators
-   [ ] Suspicious-session scoring
-   [ ] Automatic blocking
-   [ ] Security event logging

## Phase 4 --- Administration

-   [ ] Active users dashboard
-   [ ] Active sessions
-   [ ] Voucher statistics
-   [ ] Bandwidth usage
-   [ ] Security events
-   [ ] Blocked devices
-   [ ] Reports

## Phase 5 --- Production Hardening

-   [ ] VLAN segmentation
-   [ ] Secure router management
-   [ ] HTTPS
-   [ ] API authentication
-   [ ] Database backups
-   [ ] Monitoring
-   [ ] Audit logs
-   [ ] Rate limiting
-   [ ] Disaster recovery

------------------------------------------------------------------------

# 24. Recommended Final Architecture

``` text
                         INTERNET
                            |
                            v
                    +---------------+
                    |      ISP      |
                    +-------+-------+
                            |
                            v
                    +---------------+
                    |   MikroTik    |
                    |   Gateway     |
                    +-------+-------+
                            |
              +-------------+-------------+
              |                           |
              v                           v
       Customer VLAN                Management VLAN
              |                           |
              v                           v
        Access Points                Admin Systems
              |
       +------+------+------+
       |      |      |      |
      A       B      C      D
       |
       | Client isolation
       |
       v
  Captive Portal
       |
       v
  Voucher Authentication
       |
       v
  Session Enforcement
       |
       +---- Device Limit
       +---- Time Limit
       +---- Bandwidth Limit
       +---- Firewall
       +---- Tethering Detection
       |
       v
    INTERNET
```

------------------------------------------------------------------------

# 25. Final Recommendation

For a commercial Wi-Fi voucher platform, do **not** attempt to solve
hotspot sharing entirely inside the web application.

Use a layered architecture:

**Application** - Handles customers, payments, vouchers, packages,
reporting.

**Gateway** - Handles authentication, sessions, bandwidth, firewall, and
enforcement.

**Access Points** - Handle wireless connectivity and client isolation.

**Monitoring** - Detects suspicious session behavior and possible
tethering.

The most important combination is:

``` text
MikroTik Hotspot
        +
Client Isolation
        +
One Active Session / Voucher
        +
Voucher-to-Session Binding
        +
Firewall
        +
Traffic/Tethering Detection
        +
Automatic Session Termination
```

This provides a substantially stronger anti-sharing system than trying
to disable hotspot functionality on the customer's phone itself.

------------------------------------------------------------------------

## Next Technical Step

Before implementing the anti-sharing layer, identify the exact network
equipment currently being used:

``` text
1. Router/Gateway:
   MikroTik / OpenWrt / TP-Link / Other

2. Access Point:
   Ubiquiti / TP-Link / MikroTik / Other

3. Current voucher system:
   Custom backend / MikroTik Hotspot / Other

4. Backend:
   Django / FastAPI / Node.js / Other

5. Database:
   PostgreSQL / MySQL / Other
```

The implementation should then be designed around the actual gateway and
AP capabilities rather than using generic rules.
